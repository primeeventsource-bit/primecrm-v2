<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\Auth;

use Illuminate\Auth\EloquentUserProvider;

/**
 * Like EloquentUserProvider but bypasses global scopes when retrieving a
 * user.
 *
 * Why: the User model has the TenantScoped global scope, which filters
 * queries to the active tenant context. During Sanctum's session
 * authentication, the framework retrieves the user from the session
 * BEFORE the ResolveTenant middleware has had a chance to set the
 * context — so the lookup gets `WHERE 1 = 0` (TenantScope's "no tenant
 * resolved" guard) and returns null. The user appears unauthenticated.
 *
 * This provider scopes user lookups by the auth identifier alone. Once
 * auth has resolved, ResolveTenant binds the tenant context and all
 * subsequent queries on User (or any other tenant-scoped model) are
 * properly tenant-filtered.
 *
 * Wired in TenantServiceProvider via Auth::provider('tenant_unscoped',
 * ...) and selected in config/auth.php.
 */
final class TenantUnscopedUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScopes();
    }
}
