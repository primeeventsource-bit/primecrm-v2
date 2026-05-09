<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Core\Shared\TenantContext;

/**
 * Captures the tenant context at job dispatch time and re-applies it
 * inside the queue worker before the job's handle() method runs.
 *
 * Without this, queued jobs run with no tenant context and the global
 * TenantScope returns empty result sets — a silent failure mode.
 *
 * Usage:
 *   class MyJob implements ShouldQueue {
 *       use AppliesTenantContext;
 *
 *       public function __construct() {
 *           $this->captureTenantContext();
 *       }
 *
 *       public function handle() {
 *           $this->applyTenantContext();
 *           // ... job logic
 *       }
 *   }
 */
trait AppliesTenantContext
{
    public ?string $capturedTenantId = null;

    public ?string $capturedUserId = null;

    protected function captureTenantContext(): void
    {
        $context = app(TenantContext::class);
        $this->capturedTenantId = $context->id();
        $this->capturedUserId = $context->userId();
    }

    protected function applyTenantContext(): void
    {
        if ($this->capturedTenantId === null) {
            return;
        }

        app(TenantContext::class)->set(
            tenantId: $this->capturedTenantId,
            userId: $this->capturedUserId,
        );
    }
}
