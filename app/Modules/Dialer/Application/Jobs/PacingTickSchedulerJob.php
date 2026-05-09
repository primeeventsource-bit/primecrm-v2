<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Jobs;

use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Per-tick fan-out: enumerates active tenants and dispatches one
 * PacingTickJob per tenant. The actual pacing work lives in
 * PacingTickJob; this job just spreads the load.
 *
 * Scheduled every minute (or every pacing interval) in routes/console.php.
 * Scheduler uses ->onOneServer() so multiple Horizon hosts don't fan out
 * duplicate ticks.
 */
final class PacingTickSchedulerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config('queue.names.dialer'));
    }

    public function handle(): void
    {
        Tenant::query()
            ->where('status', 'active')
            ->select('id')
            ->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    PacingTickJob::dispatch($tenant->id);
                }
            });
    }
}
