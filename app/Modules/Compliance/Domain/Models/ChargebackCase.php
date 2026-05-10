<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\Compliance\Domain\Enums\ChargebackCaseStatus;
use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Processor dispute (Stripe etc) — the regulatory tail of a refund
 * that wasn't resolved internally.
 *
 * The respond_by_date is the load-bearing field: if we don't submit
 * evidence by then, we lose by default. Dashboard surfaces overdue
 * cases in red; >5 days remaining stays neutral.
 *
 * Evidence bundles include: signed contract, compliance recording IDs,
 * partner-site screenshots showing the listing was actually live,
 * and any inquiry/booking history that proves service delivery.
 */
final class ChargebackCase extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'chargeback_cases';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'processor_case_id',
        'disputed_amount',
        'reason_code',
        'respond_by_date',
        'status',
        'evidence_attached',
    ];

    protected function casts(): array
    {
        return [
            'disputed_amount' => 'decimal:2',
            'respond_by_date' => 'date',
            'status' => ChargebackCaseStatus::class,
            'evidence_attached' => 'array',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            ChargebackCaseStatus::Received->value,
            ChargebackCaseStatus::EvidenceGathering->value,
            ChargebackCaseStatus::EvidenceSubmitted->value,
        ]);
    }

    public function scopeDueSoon(Builder $q, int $days = 3): Builder
    {
        return $q->whereIn('status', [
            ChargebackCaseStatus::Received->value,
            ChargebackCaseStatus::EvidenceGathering->value,
        ])->where('respond_by_date', '<=', now()->addDays($days));
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function daysUntilDue(): ?int
    {
        if ($this->respond_by_date === null) {
            return null;
        }

        return (int) round(now()->diffInDays($this->respond_by_date, false));
    }
}
