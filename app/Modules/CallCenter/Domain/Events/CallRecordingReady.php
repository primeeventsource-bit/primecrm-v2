<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when Twilio's recording webhook reports the recording is ready
 * to fetch. DownloadCallRecordingJob handles the actual S3 transfer.
 */
final class CallRecordingReady
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
        public readonly string $providerRecordingUrl,
        public readonly string $providerRecordingSid,
    ) {}
}
