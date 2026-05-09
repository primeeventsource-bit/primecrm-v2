<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assigns a user to a commission plan over a date range.
 *
 * `overrides` is an optional JSONB blob letting tenants tweak per-user
 * rule config without forking the plan — e.g. one closer at 12% on a
 * 10% standard plan.
 */
final class CommissionAssignment extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_assignments';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'commission_plan_id',
        'effective_from',
        'effective_to',
        'overrides',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'overrides' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
