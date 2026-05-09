<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    /** @phpstan-ignore-next-line */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Scheduled jobs land here as the modules come online:
 *   Schedule::job(new RefreshFederalDncDeltaJob)->dailyAt('02:00');
 *   Schedule::job(new ExpireInventoryHoldsJob)->everyMinute();
 */
