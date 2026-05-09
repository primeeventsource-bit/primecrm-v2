<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Shared\TenantContext;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Database\Factories\TenantFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a tenant and bind it to the active TenantContext so model
     * queries pass the global TenantScope. Returns the tenant.
     */
    protected function actingAsTenant(?Tenant $tenant = null): Tenant
    {
        $tenant ??= TenantFactory::new()->create();
        app(TenantContext::class)->set($tenant->id);

        return $tenant;
    }

    /**
     * Create a user inside the active tenant context, bind it as the
     * Sanctum-authenticated user, and update TenantContext::userId.
     */
    protected function actingAsUser(
        ?Tenant $tenant = null,
        UserRole $role = UserRole::Agent,
        array $overrides = [],
    ): User {
        $tenant ??= $this->actingAsTenant();
        $user = UserFactory::new()
            ->state(['tenant_id' => $tenant->id, 'role' => $role->value, ...$overrides])
            ->create();

        app(TenantContext::class)->set($tenant->id, $user->id);
        $this->actingAs($user);

        return $user;
    }
}
