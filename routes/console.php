<?php

declare(strict_types=1);

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
 * Federal DNC delta — operators drop the file and trigger this command.
 * The cron stub is documented; uncomment and adjust the path/disk for prod.
 *
 *   Schedule::command('compliance:dnc:import-federal /tmp/federal-dnc-delta.txt --disk=s3')
 *       ->dailyAt('02:00')
 *       ->onOneServer();
 */
