<?php

declare(strict_types=1);

use App\Core\Shared\TenantContext;
use App\Modules\Tenant\Domain\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcasting Channel Authorization
|--------------------------------------------------------------------------
| Two channel families:
|
|   tenant.{tenantId}.agent.{agentId}
|     - Per-agent push for the dialer screen. Authorized only for the
|       agent themselves OR a supervisor in the same tenant.
|
|   tenant.{tenantId}.supervisor
|     - Supervisor war-room channel. Aggregated tile updates, live call
|       events, alerts. Authorized only for users with canSupervise().
*/

Broadcast::channel('tenant.{tenantId}.agent.{agentId}', function (User $user, string $tenantId, string $agentId): bool {
    if ($user->tenant_id !== $tenantId) {
        return false;
    }

    return $user->id === $agentId || $user->role->canSupervise();
});

Broadcast::channel('tenant.{tenantId}.supervisor', function (User $user, string $tenantId): bool {
    if ($user->tenant_id !== $tenantId) {
        return false;
    }

    return $user->role->canSupervise();
});

/*
 * Resolve the active tenant on every broadcast auth request. Without this,
 * the user's tenant_id check above runs against an unbound TenantContext
 * and any tenant-scoped repository call inside a channel callback would
 * return empty.
 */
app(TenantContext::class)->set(
    auth()->user()?->tenant_id ?? '',
    auth()->user()?->id,
);
