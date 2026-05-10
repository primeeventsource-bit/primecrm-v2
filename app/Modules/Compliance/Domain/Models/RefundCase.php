<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\Compliance\Domain\Enums\RefundCaseStatus;
use App\Modules\Compliance\Domain\Enums\RefundReason;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workflow row for an owner's refund request.
 *
 * Distinct from the row-level Payment refund — that's the financial
 * event. This is the investigation, decision, and audit trail wrapped
 * around it. Cases that escalate end up as ChargebackCase rows.
 */
final class RefundCase extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'refund_cases';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'opened_by',
        'refund_amount',
        'reason',
        'owner_statement',
        'status',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'reason' => RefundReason::class,
            'status' => RefundCaseStatus::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            RefundCaseStatus::Opened->value,
            RefundCaseStatus::Investigating->value,
            RefundCaseStatus::Approved->value,
        ]);
    }

    public function scopeHighRisk(Builder $q): Builder
    {
        return $q->whereIn('reason', [
            RefundReason::MisrepresentationClaim->value,
            RefundReason::Unauthorized->value,
            RefundReason::ServiceNotDelivered->value,
        ]);
    }
}
