<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed data for tenants, demo users, sample leads, etc. lands in
        // Response 5. Keeping this empty keeps `php artisan db:seed` from
        // exploding on a fresh deploy while still being a registered seeder.
    }
}
