<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Core\Shared\TenantContext;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resets the per-request tenant binding between Octane requests so a worker
 * that handled tenant A's request never leaks state into tenant B's request.
 *
 * Listed in config/octane.php under the RequestTerminated event.
 */
final class FlushTenantContext
{
    public function __construct(private readonly Application $app) {}

    public function handle(): void
    {
        if ($this->app->bound(TenantContext::class)) {
            $this->app->make(TenantContext::class)->clear();
        }
    }
}
