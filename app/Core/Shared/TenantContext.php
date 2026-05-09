<?php

declare(strict_types=1);

namespace App\Core\Shared;

/**
 * Request-scoped tenant context.
 *
 * Resolved by TenantMiddleware for HTTP requests, or set explicitly
 * by queued jobs via the AppliesTenantContext trait. Accessed by the
 * TenantScoped global scope on every model query.
 *
 * Bound as a singleton in the request lifecycle.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?string $userId = null;

    public function set(string $tenantId, ?string $userId = null): void
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->userId = null;
    }

    public function isResolved(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Run a callback under a different tenant context (system jobs only).
     * Restores the previous context after the callback completes.
     */
    public function runAs(string $tenantId, ?string $userId, callable $callback): mixed
    {
        $previousTenant = $this->tenantId;
        $previousUser = $this->userId;

        $this->set($tenantId, $userId);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenant;
            $this->userId = $previousUser;
        }
    }
}
