<?php

declare(strict_types=1);

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\CallParticipant;
use App\Modules\Tenant\Domain\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcasting Channel Authorization
|--------------------------------------------------------------------------
| Three channel families:
|
|   tenant.{tenantId}.agent.{agentId}
|     - Per-agent push for the dialer screen. Authorized only for the
|       agent themselves OR a supervisor in the same tenant.
|
|   tenant.{tenantId}.supervisor
|     - Supervisor war-room channel. Aggregated tile updates, live call
|       events, alerts. Authorized only for users with canSupervise().
|
|   tenant.{tenantId}.room.{roomSid}
|     - Per Prime Connect video room. Carries participant lifecycle and
|       in-call coordination broadcasts. Authorized only for users who
|       are listed as participants on the room (CallParticipant.user_id)
|       OR users with canSupervise() in the same tenant. Customers (who
|       aren't users) join Twilio directly via the JWT and never hit
|       this Echo channel — supervisors are the only Echo subscribers.
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

Broadcast::channel('tenant.{tenantId}.room.{roomSid}', function (User $user, string $tenantId, string $roomSid): bool {
    if ($user->tenant_id !== $tenantId) {
        return false;
    }

    // Supervisors see every room in their tenant — needed for war-room
    // observation without forcing a roster lookup on every channel auth.
    if ($user->role->canSupervise()) {
        return true;
    }

    // Otherwise the user must be a roster participant on this specific
    // room. The Call lookup is tenant-scoped via the global TenantScope
    // (set just below this block), so a cross-tenant SID is treated
    // as "not found" rather than "exists but you can't see it".
    $call = Call::query()->where('twilio_room_sid', $roomSid)->first();
    if ($call === null) {
        return false;
    }

    return CallParticipant::query()
        ->where('call_id', $call->id)
        ->where('user_id', $user->id)
        ->exists();
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
