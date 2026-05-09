<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\CallDirection;
use App\Support\Enums\CallStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Places an outbound call via the telephony provider.
 *
 * IMPORTANT: this is *not* the place where compliance is checked. The
 * compliance guardrail runs in DialLeadJob (Dialer module), upstream of
 * this action. Anything that gets here has already been cleared by the
 * pre-dial pipeline. Re-checking here would be a layering violation.
 *
 * What this DOES guarantee:
 *   - The `calls` row is created and persisted before the provider call
 *     fires, so a webhook arriving microseconds later already has a row
 *     to land on.
 *   - The CallSid is stamped via CallStateService::markInitiated which
 *     writes the call_events row with idempotency_key="init:{sid}". The
 *     subsequent webhook for "initiated" status (which Twilio fires too)
 *     will collide on the unique key and no-op.
 *   - Provider failures roll back the calls row. We don't want orphan
 *     "queued" rows piling up when Twilio returns a 4xx.
 */
final class InitiateCallAction
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly CallStateService $callState,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $extras  passed through to the provider
     */
    public function execute(
        Lead $lead,
        string $agentId,
        ?string $dialSessionId,
        ?string $campaignId,
        array $extras = [],
    ): Call {
        $config = config('telephony.providers.twilio');
        $fromNumber = $extras['from'] ?? $config['from_number'];
        if (empty($fromNumber)) {
            throw new \RuntimeException('No outbound caller ID configured (TWILIO_FROM_NUMBER).');
        }

        $webhookBase = $config['webhook_base_url'];
        if (empty($webhookBase)) {
            throw new \RuntimeException('TWILIO_WEBHOOK_BASE_URL must be set for outbound calls.');
        }

        // 1) Create the placeholder call row first. Do NOT release the DB
        // transaction until the call_events seed row is also written, so a
        // late webhook can't see a half-initialized call.
        $call = DB::transaction(function () use ($lead, $agentId, $dialSessionId, $campaignId, $fromNumber): Call {
            $call = Call::query()->create([
                'id' => Uuid::uuid7()->toString(),
                'lead_id' => $lead->id,
                'agent_id' => $agentId,
                'dial_session_id' => $dialSessionId,
                'campaign_id' => $campaignId,
                'provider' => 'twilio',
                'from_number' => $fromNumber,
                'to_number' => $lead->phone,
                'direction' => CallDirection::Outbound->value,
                'status' => CallStatus::Queued->value,
                'queued_at' => now(),
            ]);

            $this->callState->appendEvent(
                $call,
                eventType: 'queued',
                source: 'system',
                payload: [
                    'lead_id' => $lead->id,
                    'agent_id' => $agentId,
                    'dial_session_id' => $dialSessionId,
                ],
                idempotencyKey: 'queued:'.$call->id,
            );

            return $call;
        });

        // 2) Place the actual call. If this fails, mark the call as failed
        // (don't leave it stuck in 'queued' forever).
        try {
            $voiceUrl = rtrim($webhookBase, '/').'/webhooks/twilio/voice/'.$call->id;
            $statusUrl = rtrim($webhookBase, '/').'/webhooks/twilio/status/'.$call->id;
            $recordingUrl = rtrim($webhookBase, '/').'/webhooks/twilio/recording/'.$call->id;

            $snapshot = $this->provider->placeCall(
                from: $fromNumber,
                to: $lead->phone,
                voiceUrl: $voiceUrl,
                statusCallbackUrl: $statusUrl,
                recordingStatusCallbackUrl: $recordingUrl,
                extras: $extras,
            );
        } catch (\Throwable $e) {
            $this->callState->markEnded($call->fresh(), CallStatus::Failed, payload: [
                'failure_reason' => $e->getMessage(),
                'failure_class' => $e::class,
            ]);

            $this->audit->record(
                action: 'call.provider_failed',
                entityType: 'call',
                entityId: $call->id,
                context: ['error' => $e->getMessage()],
            );

            throw $e;
        }

        // 3) Stamp the SID and transition to initiated.
        $call = $this->callState->markInitiated($call, $snapshot->sid, $snapshot->raw);

        $this->audit->record(
            action: 'call.initiated',
            entityType: 'call',
            entityId: $call->id,
            context: [
                'lead_id' => $lead->id,
                'agent_id' => $agentId,
                'provider_sid' => $snapshot->sid,
            ],
        );

        return $call;
    }
}
