<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default seeder. In dev `php artisan db:seed` runs this; production
 * deployments don't run it (no demo data in prod).
 *
 * For a populated demo environment, run:
 *   php artisan db:seed --class=DemoSeeder
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Always-on seed data (federal calling-window default).
        $this->call(BaseCallingWindowsSeeder::class);

        // Default commission plans for every existing tenant. Idempotent;
        // safe to re-run after schema or rate changes.
        $this->call(CommissionPlansSeeder::class);
    }
}
