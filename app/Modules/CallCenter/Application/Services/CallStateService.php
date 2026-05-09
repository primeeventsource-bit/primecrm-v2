<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Events\CallConnected;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\CallEvent;
use App\Support\Enums\CallStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Owns the call's state machine. Every transition is:
 *
 *   1. Append to call_events (with idempotency_key when the source has one)
 *   2. Update calls.status / timing fields atomically
 *   3. Dispatch the domain event so listeners react
 *
 * The append-then-update pattern means the event log is always at least
 * as new as the row state. If we crash between #1 and #2, a recovery job
 * can replay tail events to restore the row. We don't have that recovery
 * job today — but the design is forward-compatible.
 *
 * State transitions are intentionally permissive: webhooks arrive out of
 * order in the wild, and rejecting "ringing after in_progress" causes
 * more bugs than it prevents. We let the latest valid state win and
 * record everything in call_events.
 */
final class CallStateService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Mark a queued call as initiated (Twilio responded with a SID).
     */
    public function markInitiated(Call $call, string $providerSid, ?array $payload = null): Call
    {
        DB::transaction(function () use ($call, $providerSid, $payload): void {
            $this->appendEvent($call, 'initiated', 'system', $payload ?? [], "init:{$providerSid}");

            $call->update([
                'provider_call_sid' => $providerSid,
                'status' => CallStatus::Initiated->value,
                'initiated_at' => $call->initiated_at ?? now(),
            ]);
        });

        CallInitiated::dispatch($call->fresh());

        return $call->fresh();
    }

    /**
     * Webhook update: ringing.
     */
    public function markRinging(Call $call, ?string $idempotencyKey = null, ?array $payload = null): Call
    {
        DB::transaction(function () use ($call, $idempotencyKey, $payload): void {
            $this->appendEvent($call, 'ringing', 'twilio_webhook', $payload ?? [], $idempotencyKey);

            // Don't downgrade if we've already advanced past ringing.
            if (! in_array($call->status?->value, [
                CallStatus::InProgress->value,
                CallStatus::Completed->value,
                CallStatus::Failed->value,
                CallStatus::Busy->value,
                CallStatus::NoAnswer->value,
                CallStatus::Canceled->value,
            ], true)) {
                $call->update(['status' => CallStatus::Ringing->value]);
            }
        });

        return $call->fresh();
    }

    /**
     * Webhook update: in-progress / answered.
     */
    public function markAnswered(
        Call $call,
        ?string $idempotencyKey = null,
        ?array $payload = null,
    ): Call {
        $wasConnected = $call->answered_at !== null;

        DB::transaction(function () use ($call, $idempotencyKey, $payload): void {
            $this->appendEvent($call, 'answered', 'twilio_webhook', $payload ?? [], $idempotencyKey);

            $updates = ['status' => CallStatus::InProgress->value];
            if ($call->answered_at === null) {
                $updates['answered_at'] = now();
                if ($call->initiated_at !== null) {
                    $updates['ring_seconds'] = max(0, now()->diffInSeconds($call->initiated_at));
                }
            }
            $call->update($updates);
        });

        if (! $wasConnected) {
            CallConnected::dispatch($call->fresh());
        }

        return $call->fresh();
    }

    /**
     * Webhook update: terminal status (completed/busy/no-answer/failed/canceled).
     */
    public function markEnded(
        Call $call,
        CallStatus $finalStatus,
        ?string $idempotencyKey = null,
        ?array $payload = null,
    ): Call {
        if ($call->isTerminal() && $call->status === $finalStatus) {
            // Already terminal at this status — Twilio sometimes retries.
            // The idempotency_key on call_events guarantees no duplicate row.
            return $call;
        }

        $previousStatus = $call->status?->value ?? 'unknown';

        DB::transaction(function () use ($call, $finalStatus, $idempotencyKey, $payload): void {
            $this->appendEvent($call, 'ended', 'twilio_webhook', $payload ?? [], $idempotencyKey);

            $endedAt = now();
            $duration = ($call->answered_at !== null)
                ? max(0, $endedAt->diffInSeconds($call->answered_at))
                : 0;

            $call->update([
                'status' => $finalStatus->value,
                'ended_at' => $endedAt,
                'duration_seconds' => $duration,
            ]);
        });

        CallEnded::dispatch($call->fresh(), $previousStatus);

        return $call->fresh();
    }

    /**
     * Persist a disposition + free-form notes from the agent. This is the
     * single place that writes disposition — it's part of the audit trail.
     */
    public function setDisposition(Call $call, string $disposition, ?string $notes = null): Call
    {
        DB::transaction(function () use ($call, $disposition, $notes): void {
            $this->appendEvent($call, 'disposition_set', 'agent_action', [
                'disposition' => $disposition,
                'notes' => $notes,
            ]);

            $call->update([
                'disposition' => $disposition,
                'disposition_notes' => $notes,
            ]);
        });

        return $call->fresh();
    }

    /**
     * Append-only event write. Idempotency key + unique constraint on the
     * column means a duplicate INSERT throws a 23505 — caught here and
     * treated as "already processed", returning silently.
     */
    public function appendEvent(
        Call $call,
        string $eventType,
        string $source,
        array $payload,
        ?string $idempotencyKey = null,
    ): void {
        try {
            CallEvent::query()->create([
                'tenant_id' => $call->tenant_id,
                'call_id' => $call->id,
                'event_type' => $eventType,
                'source' => $source,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey ?? Uuid::uuid7()->toString(),
                'occurred_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Already recorded — webhook retry, harmless.
        }
    }
}
