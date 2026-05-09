<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Jobs;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a Twilio recording to our recording disk (S3 in prod).
 *
 * Stream-based: we don't want to load multi-MB files into PHP memory.
 * fopen() returns a resource, fputstream-ish to S3. Failure modes:
 *
 *   - Twilio 404: recording was deleted (Twilio retention) — retry-loop
 *     would never succeed; we mark the call's recording_status='absent'
 *     and stop.
 *   - S3 401/403: configuration; let the job fail and retry with backoff.
 *
 * The S3 key format is tenant-partitioned so per-tenant lifecycle
 * policies (retention, encryption) apply uniformly:
 *
 *   recordings/{tenant_id}/{YYYY}/{MM}/{call_id}.mp3
 */
final class DownloadCallRecordingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(
        public readonly string $callId,
        public readonly string $providerRecordingSid,
    ) {
        $this->onQueue(config('queue.names.recordings'));
    }

    public function handle(TelephonyProvider $provider, TenantContext $tenantContext): void
    {
        $call = Call::query()->withoutTenantScope()->find($this->callId);
        if ($call === null) {
            return;
        }

        $tenantContext->set($call->tenant_id);

        if ($call->recording_s3_path !== null) {
            return; // already downloaded
        }

        $disk = config('telephony.recording.storage_disk');
        $basePath = rtrim((string) config('telephony.recording.storage_path'), '/');
        $month = now()->format('Y/m');
        $s3Path = "{$basePath}/{$call->tenant_id}/{$month}/{$call->id}.mp3";

        $stream = null;
        try {
            $stream = $provider->downloadRecording($this->providerRecordingSid);

            $options = (bool) config('telephony.recording.encrypt')
                ? ['ServerSideEncryption' => 'AES256']
                : [];

            Storage::disk($disk)->writeStream($s3Path, $stream, $options);

            $call->update(['recording_s3_path' => $s3Path]);
        } catch (\Throwable $e) {
            // Twilio 404 is permanent — don't keep retrying.
            $message = $e->getMessage();
            if (str_contains($message, '404') || str_contains($message, 'NotFound')) {
                $call->update(['recording_status' => 'absent']);
                logger()->warning('Recording not found at provider', [
                    'call_id' => $call->id,
                    'recording_sid' => $this->providerRecordingSid,
                ]);

                return;
            }
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
