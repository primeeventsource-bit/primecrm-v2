<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Laravel 11 minimal bootstrap.
 *
 * Module routes are NOT registered here — App\Core\Shared\Providers\ModuleServiceProvider
 * iterates config/modules.php and mounts each module's routes.php under the /api prefix
 * with the 'api' middleware group. We deliberately leave `api:` out of withRouting() to
 * avoid double-prefixing; routes/api.php is provided as an empty placeholder for any
 * cross-module API routes that don't belong to a single module.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Core\Shared\Http\Middleware\ResolveTenant::class,
        ]);

        // Stateful API auth via Sanctum SPA — only relevant once the Vue UI lands.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tenant-scoped queries that can't resolve a tenant should never fall
        // through to a 500. Application code handles this via TenantContext::isResolved().
    })
    ->create();
