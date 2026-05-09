<?php

declare(strict_types=1);

namespace App\Modules\Tenant;

use Illuminate\Support\ServiceProvider;

final class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reserved for tenant-specific bindings as the module grows.
    }

    public function boot(): void
    {
        //
    }
}
