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
 * Derived commission line — what one rule, applied to one event, owes one user.
 *
 * Reversals: rather than mutating an original calculation, the engine
 * writes a NEW row with `is_reversal=true`, a NEGATIVE `amount`, and
 * `reverses_calculation_id` pointing back. This preserves the audit
 * trail through chargebacks and refunds and makes payouts trivially
 * computable as a SUM(amount) over the period.
 */
final class CommissionCalculation extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_calculations';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAYABLE = 'payable';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'tenant_id',
        'commission_event_id',
        'user_id',
        'commission_plan_rule_id',
        'role',
        'base_amount',
        'rate',
        'amount',
        'currency',
        'explanation',
        'is_reversal',
        'reverses_calculation_id',
        'status',
        'payable_period',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'explanation' => 'array',
            'is_reversal' => 'boolean',
            'payable_period' => 'date',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CommissionEvent::class, 'commission_event_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionPlanRule::class, 'commission_plan_rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_calculation_id');
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PAYABLE, self::STATUS_PENDING]);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPeriod(Builder $query, string $start, string $end): Builder
    {
        return $query
            ->whereDate('payable_period', '>=', $start)
            ->whereDate('payable_period', '<=', $end);
    }
}
