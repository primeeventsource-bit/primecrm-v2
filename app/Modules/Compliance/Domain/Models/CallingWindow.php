<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-jurisdiction calling-time rules.
 *
 * NOT TenantScoped — federal default rules (jurisdiction = 'US-FED',
 * tenant_id NULL) are shared across all tenants. Per-tenant overrides
 * exist for stricter internal policy (e.g. a tenant that wants 9am–8pm
 * regardless of state law).
 *
 * Resolution precedence: tenant-specific row > federal default. The
 * CallingWindowService selects the most specific rule that applies to
 * the lead's state.
 */
final class CallingWindow extends Model
{
    use HasUuid;

    protected $table = 'calling_windows';

    protected $fillable = [
        'tenant_id',
        'jurisdiction',
        'earliest_local',
        'latest_local',
        'blocked_weekdays',
        'blocked_dates',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'earliest_local' => 'string', // stored as 'HH:MM:SS'
            'latest_local' => 'string',
            'blocked_weekdays' => 'array',
            'blocked_dates' => 'array',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeForJurisdiction(Builder $query, string $jurisdiction): Builder
    {
        return $query->where('jurisdiction', $jurisdiction);
    }
}
