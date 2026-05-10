<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Enums\AgreementStatus;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\DealStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Deal aggregate.
 *
 * A Deal is what gets paid for. It has:
 *   - one Lead (the customer)
 *   - one primary closer (agent_id)
 *   - optionally a fronter (who handed off the lead)
 *   - optionally additional closers (multi-closer split scenarios)
 *
 * Money structure: total_value − snr_amount − vd_amount = payable_amount.
 * That last column is what drives commission calculations on payment.cleared.
 */
final class Deal extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'deals';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'agent_id',
        'fronter_id',
        'additional_closer_ids',
        'stage',
        'previous_stage',
        'stage_changed_at',
        'lost_reason',
        'total_value',
        'snr_amount',
        'vd_amount',
        'payable_amount',
        'currency',
        'booking_id',
        'contract_id',
        'pitch_data',
        'notes',
        'expected_close_at',
        'closed_at',
        // Listing-agreement augmentation (timeshare domain).
        'listing_fee',
        'listing_fee_collected',
        'payment_status',
        'agreement_status',
        'listing_term_months',
        'term_expires_at',
        'refund_window_expires_at',
        'tcpa_disclosure_completed',
        'tcpa_disclosure_completed_at',
        'tcpa_recording_uri',
        'verification_call_completed',
        'verification_call_completed_at',
        'verifier_id',
        'agreement_signed_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => DealStage::class,
            'additional_closer_ids' => 'array',
            'pitch_data' => 'array',
            'total_value' => 'decimal:2',
            'snr_amount' => 'decimal:2',
            'vd_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'stage_changed_at' => 'datetime',
            'expected_close_at' => 'datetime',
            'closed_at' => 'datetime',
            // Listing-agreement augmentation
            'agreement_status' => AgreementStatus::class,
            'listing_fee' => 'decimal:2',
            'listing_fee_collected' => 'decimal:2',
            'listing_term_months' => 'integer',
            'term_expires_at' => 'date',
            'refund_window_expires_at' => 'date',
            'tcpa_disclosure_completed' => 'boolean',
            'tcpa_disclosure_completed_at' => 'datetime',
            'verification_call_completed' => 'boolean',
            'verification_call_completed_at' => 'datetime',
            'agreement_signed_at' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function fronter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fronter_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DealStageTransition::class);
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [
            DealStage::ClosedWon->value,
            DealStage::ClosedLost->value,
        ]);
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('stage', DealStage::ClosedWon->value);
    }

    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function isWon(): bool
    {
        return $this->stage instanceof DealStage && $this->stage === DealStage::ClosedWon;
    }

    public function isClosed(): bool
    {
        return $this->stage instanceof DealStage && in_array($this->stage, [
            DealStage::ClosedWon,
            DealStage::ClosedLost,
        ], true);
    }

    /**
     * Recompute payable_amount from total_value − snr − vd.
     * Called from setters; doesn't persist.
     */
    public function recomputePayable(): void
    {
        $this->payable_amount = (float) $this->total_value
            - (float) $this->snr_amount
            - (float) $this->vd_amount;
    }
}
