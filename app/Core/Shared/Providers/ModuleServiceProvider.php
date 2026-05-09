<?php

declare(strict_types=1);

namespace App\Core\Shared\Providers;

use App\Core\Shared\TenantContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Boots each module declared in config/modules.php.
 *
 * For each module, this provider:
 *   1. Loads routes from app/Modules/{Module}/routes.php (if it exists)
 *   2. Loads the module-specific service provider (if it exists)
 *
 * Migrations live centrally in /database/migrations rather than per-module.
 * That's a deliberate choice — it gives Laravel's migrator a single source
 * of truth and makes ordering across module dependencies explicit.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TenantContext is bound per-request as a singleton.
        // Octane workers reset this between requests via the FlushTenantContext listener.
        $this->app->singleton(TenantContext::class);

        $this->registerModuleProviders();
    }

    public function boot(): void
    {
        $this->registerModuleRoutes();
    }

    private function registerModuleProviders(): void
    {
        $modules = config('modules.modules', []);
        $namespace = config('modules.namespace', 'App\\Modules');

        foreach ($modules as $module) {
            $providerClass = "{$namespace}\\{$module}\\{$module}ServiceProvider";

            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }

    private function registerModuleRoutes(): void
    {
        $modules = config('modules.modules', []);
        $modulePath = config('modules.path');

        foreach ($modules as $module) {
            $routeFile = "{$modulePath}/{$module}/routes.php";

            if (file_exists($routeFile)) {
                Route::middleware('api')
                    ->prefix('api')
                    ->group($routeFile);
            }
        }
    }
}
