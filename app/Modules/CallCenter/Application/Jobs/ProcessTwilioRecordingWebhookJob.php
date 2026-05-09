<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Jobs;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Application\Services\WebhookEventStore;
use App\Modules\CallCenter\Domain\Events\CallRecordingReady;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Twilio recording.completed webhook processor.
 *
 * Updates the Call's recording fields with what Twilio reports, then
 * dispatches DownloadCallRecordingJob to actually pull the file to S3.
 * The download is separated because it's bandwidth-heavy and shouldn't
 * block recording-status webhook processing for other calls.
 */
final class ProcessTwilioRecordingWebhookJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $webhookEventId,
        public readonly string $callId,
    ) {
        $this->onQueue(config('queue.names.webhooks_twilio'));
    }

    public function handle(
        CallStateService $callState,
        WebhookEventStore $store,
        TenantContext $tenantContext,
    ): void {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null) {
            return;
        }
        if ($event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $store->markProcessing($event);

        try {
            $call = Call::query()->withoutTenantScope()->find($this->callId);
            if ($call === null) {
                $store->markProcessed($event);

                return;
            }

            $tenantContext->set($call->tenant_id);

            $payload = $event->payload;
            $recordingSid = (string) ($payload['RecordingSid'] ?? '');
            $recordingUrl = (string) ($payload['RecordingUrl'] ?? '');
            $recordingStatus = (string) ($payload['RecordingStatus'] ?? '');
            $duration = isset($payload['RecordingDuration'])
                ? (int) $payload['RecordingDuration']
                : null;

            $callState->appendEvent(
                $call,
                eventType: "recording_{$recordingStatus}",
                source: 'twilio_webhook',
                payload: $payload,
                idempotencyKey: "twilio_rec:{$recordingSid}:{$recordingStatus}",
            );

            $call->update([
                'recording_status' => $recordingStatus,
                'recording_provider_sid' => $recordingSid,
                'recording_url' => $recordingUrl,
                'recording_duration_seconds' => $duration,
            ]);

            if ($recordingStatus === 'completed' && $recordingSid !== '') {
                CallRecordingReady::dispatch($call->fresh(), $recordingUrl, $recordingSid);
                DownloadCallRecordingJob::dispatch($call->id, $recordingSid);
            }

            $store->markProcessed($event, $call->tenant_id);
        } catch (\Throwable $e) {
            $store->markFailed($event, $e->getMessage());
            throw $e;
        }
    }
}
