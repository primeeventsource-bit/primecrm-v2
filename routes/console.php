<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Jobs\ExpireInventoryHoldsJob;
use App\Modules\Dialer\Application\Jobs\PacingTickSchedulerJob;
use App\Modules\Lead\Application\Jobs\ReassignStaleLeadsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    /** @phpstan-ignore-next-line */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Lead routing maintenance.
 *
 * Sweeps every active tenant for leads idle past the stale window
 * (config: leads.assignment.stale_assignment_minutes) and reassigns them.
 * Single-instance scoped via withoutOverlapping — multiple horizon hosts
 * won't double-fire it.
 */
Schedule::job(new ReassignStaleLeadsJob)
    ->everyMinute()
    ->name('leads:reassign-stale')
    ->withoutOverlapping(5)
    ->onOneServer();

/*
 * Predictive dialer pacing tick.
 *
 * Fires every 30 seconds (matches telephony.predictive.pacing_interval_seconds
 * default). The fan-out scheduler enumerates active tenants and dispatches one
 * PacingTickJob per tenant onto the dialer queue. PacingTickJob then iterates
 * the tenant's active campaigns and dispatches DialLeadJobs based on the
 * PacingEngine's decision.
 *
 * Sub-minute frequency requires `php artisan schedule:work` (we run it as a
 * separate Cloud / Docker process). onOneServer() prevents two Horizon hosts
 * fanning out duplicate ticks; the limit on overlapping is intentionally short
 * (25s) so a stalled tick doesn't block the next one beyond one cycle.
 */
Schedule::job(new PacingTickSchedulerJob)
    ->everyThirtySeconds()
    ->name('dialer:pacing-tick')
    ->withoutOverlapping(25)
    ->onOneServer();

/*
 * Inventory hold expiration.
 *
 * Sweeps inventory_holds with expires_at <= now() and released_at IS NULL,
 * marks them expired and frees the underlying inventory_availability row
 * back to 'available' status. The HoldService::expireStale uses
 * withoutTenantScope() since this is a system-level sweep.
 */
Schedule::job(new ExpireInventoryHoldsJob)
    ->everyMinute()
    ->name('booking:expire-holds')
    ->withoutOverlapping(2)
    ->onOneServer();

/*
 * Federal DNC delta — operators drop the file and trigger this command.
 * The cron stub is documented; uncomment and adjust the path/disk for prod.
 *
 *   Schedule::command('compliance:dnc:import-federal /tmp/federal-dnc-delta.txt --disk=s3')
 *       ->dailyAt('02:00')
 *       ->onOneServer();
 */
