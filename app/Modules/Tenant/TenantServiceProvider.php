<?php

declare(strict_types=1);

namespace App\Modules\Tenant;

use App\Modules\Tenant\Application\Auth\TenantUnscopedUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

final class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reserved for tenant-specific bindings as the module grows.
    }

    public function boot(): void
    {
        // Register a user provider that bypasses global scopes during auth
        // lookups. The User model has the TenantScoped global scope, which
        // returns empty when no tenant context is set — and Sanctum's
        // session-based auth retrieves the user BEFORE ResolveTenant runs.
        // Without this provider, every authenticated request gets 401.
        //
        // See: App\Modules\Tenant\Application\Auth\TenantUnscopedUserProvider
        Auth::provider('tenant_unscoped', function ($app, array $config) {
            return new TenantUnscopedUserProvider($app['hash'], $config['model']);
        });

        // Point the default `users` provider at the new driver. config('auth.providers.users')
        // is read lazily by the AuthManager when the guard resolves, so a runtime
        // override here takes effect on the very next request.
        config(['auth.providers.users.driver' => 'tenant_unscoped']);
    }
}
