<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual ± entry against a user's payout for a period.
 *
 * Used for spiffs ("hit your monthly: +$500"), corrections, claw-backs
 * outside the normal chargeback flow, and one-off bonuses. Operators
 * with payroll authority create these; the audit log records who & why.
 */
final class CommissionAdjustment extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_adjustments';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'amount',
        'reason',
        'description',
        'created_by_id',
        'payable_period',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payable_period' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
