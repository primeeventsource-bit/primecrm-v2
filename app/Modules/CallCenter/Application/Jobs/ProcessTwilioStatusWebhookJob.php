<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Jobs;

use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Application\Services\WebhookEventStore;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use App\Support\Enums\CallStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async processor for Twilio status callbacks.
 *
 * The HTTP controller does the absolute minimum (verify signature, ingest
 * to webhook_events, dispatch this job, 200 OK) so Twilio's retry budget
 * is not wasted on slow request handling. This job does the actual work
 * of moving the Call's state machine forward.
 *
 * Tenant scope: webhooks arrive without a resolved tenant, so this job
 * resolves the tenant FROM the call_id encoded in the Twilio voice URL
 * we issued. The Call row itself is queried with `withoutTenantScope()`
 * because we don't have a context yet — we look up by id, then bind the
 * tenant context for the rest of the job's work.
 */
final class ProcessTwilioStatusWebhookJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 2;

    /** @var array<int> exponential-ish backoff in seconds */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public readonly string $webhookEventId,
        public readonly string $callId,
    ) {
        $this->onQueue(config('queue.names.webhooks_twilio'));
    }

    public function handle(
        CallStateService $callState,
        WebhookEventStore $store,
        \App\Core\Shared\TenantContext $tenantContext,
    ): void {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null) {
            return;
        }

        if ($event->status === WebhookEvent::STATUS_PROCESSED) {
            return; // already done; harmless re-dispatch
        }

        $store->markProcessing($event);

        try {
            $call = Call::query()->withoutTenantScope()->find($this->callId);

            if ($call === null) {
                // Call row deleted or never existed (stray webhook). Mark
                // processed so we don't retry — there's nothing to do.
                $store->markProcessed($event);

                return;
            }

            $tenantContext->set($call->tenant_id);

            $payload = $event->payload;
            $providerStatus = (string) ($payload['CallStatus'] ?? '');
            $sid = (string) ($payload['CallSid'] ?? '');

            // Map Twilio statuses to ours. Twilio uses dashes, our enum uses
            // underscores; "in-progress" → "in_progress", "no-answer" → "no_answer".
            $idempotencyKey = "twilio:{$sid}:{$providerStatus}";

            switch ($providerStatus) {
                case 'queued':
                case 'initiated':
                    if ($call->provider_call_sid === null) {
                        $callState->markInitiated($call, $sid, $payload);
                    } else {
                        $callState->appendEvent($call, $providerStatus, 'twilio_webhook', $payload, $idempotencyKey);
                    }
                    break;

                case 'ringing':
                    $callState->markRinging($call, $idempotencyKey, $payload);
                    break;

                case 'in-progress':
                    $callState->markAnswered($call, $idempotencyKey, $payload);
                    break;

                case 'completed':
                case 'busy':
                case 'no-answer':
                case 'failed':
                case 'canceled':
                    $finalStatus = match ($providerStatus) {
                        'completed' => CallStatus::Completed,
                        'busy' => CallStatus::Busy,
                        'no-answer' => CallStatus::NoAnswer,
                        'failed' => CallStatus::Failed,
                        'canceled' => CallStatus::Canceled,
                        default => CallStatus::Failed,
                    };
                    $callState->markEnded($call, $finalStatus, $idempotencyKey, $payload);
                    break;

                default:
                    $callState->appendEvent($call, "unknown_status:{$providerStatus}", 'twilio_webhook', $payload, $idempotencyKey);
            }

            $store->markProcessed($event, $call->tenant_id);
        } catch (\Throwable $e) {
            $store->markFailed($event, $e->getMessage());
            throw $e;
        }
    }
}
