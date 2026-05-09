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
 * Period rollup of commissions for one user.
 *
 * Computed by PayoutService::buildForPeriod — sums calculations in the
 * period's range, separating earnings, reversals, and adjustments.
 * `net_payable = total_earned - total_reversed + total_adjustments`.
 *
 * The unique constraint (tenant_id, user_id, period_start, period_end)
 * means there's exactly one payout row per user per period — re-running
 * the build doesn't create duplicates; it updates in place if status='draft'.
 */
final class CommissionPayout extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_payouts';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'period_start',
        'period_end',
        'total_earned',
        'total_reversed',
        'total_adjustments',
        'net_payable',
        'currency',
        'status',
        'approved_by_id',
        'approved_at',
        'paid_at',
        'payment_reference',
        'calculation_ids',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_earned' => 'decimal:2',
            'total_reversed' => 'decimal:2',
            'total_adjustments' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'calculation_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_APPROVED]);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_VOIDED], true);
    }
}
