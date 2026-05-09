<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compliance\Domain\Models\CallingWindow;
use Illuminate\Database\Seeder;

/**
 * Federal-default calling window (US-FED, 8am–9pm). Required for the
 * compliance guardrail's calling-window check to find any rule on a
 * fresh tenant. Without this, every dial gets rejected with
 * `outside_calling_window` until an operator configures one manually.
 */
final class BaseCallingWindowsSeeder extends Seeder
{
    public function run(): void
    {
        CallingWindow::query()->updateOrCreate(
            ['tenant_id' => null, 'jurisdiction' => 'US-FED'],
            [
                'earliest_local' => '08:00:00',
                'latest_local' => '21:00:00',
                'blocked_weekdays' => [],
                'blocked_dates' => [],
                'active' => true,
            ],
        );
    }
}
