<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Jobs;

use App\Modules\Booking\Application\Services\HoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sweeps expired holds across all tenants and releases them.
 * Scheduled in routes/console.php to run every minute. The HoldService
 * uses withoutTenantScope() because this is a system sweep — we don't
 * have a single tenant context.
 */
final class ExpireInventoryHoldsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(config('queue.names.default'));
    }

    public function handle(HoldService $holds): void
    {
        $count = $holds->expireStale();

        if ($count > 0) {
            logger()->info('Inventory holds expired', ['count' => $count]);
        }
    }
}
