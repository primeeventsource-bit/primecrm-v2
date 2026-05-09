<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Core\Shared\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Enforces tenant isolation on every model query.
 *
 * CRITICAL: Every tenant-scoped table MUST use this trait. There is no
 * legitimate reason to query across tenants from a request lifecycle.
 * Bypassing this is reserved for system jobs (reporting rollups, cleanup)
 * and must use ::withoutTenantScope() explicitly.
 */
trait TenantScoped
{
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (empty($model->tenant_id)) {
                $tenantId = app(TenantContext::class)->id();

                if ($tenantId === null) {
                    throw new \RuntimeException(
                        sprintf(
                            'Cannot create %s without a resolved tenant context. '
                            .'Ensure the tenant middleware has run, or set context explicitly in jobs.',
                            static::class
                        )
                    );
                }

                $model->tenant_id = $tenantId;
            }
        });
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            // No tenant resolved — return empty result set rather than leak data.
            // Jobs that legitimately need cross-tenant access must call withoutTenantScope().
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.tenant_id', $tenantId);
    }
}
