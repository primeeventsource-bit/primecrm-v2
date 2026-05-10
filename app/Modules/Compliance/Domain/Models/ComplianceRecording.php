<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\Compliance\Domain\Enums\ComplianceStatus;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disclosure-capture overlay on a sales call that closed a listing fee.
 *
 * The call recording itself lives on the parent `calls` row (URL,
 * transcription, sentiment). This row only carries the per-disclosure
 * pass markers and the reviewer state. One row per call (unique).
 *
 * Required disclosures for timeshare resale/rental in the US:
 *   - TCPA consent captured
 *   - Recording disclosure made
 *   - No-rental-guarantee disclosure made
 *   - Refund policy disclosed
 *   - Total fee stated clearly
 *
 * Until all five are true, the parent deal cannot leave
 * AgreementStatus::PaidPendingVerification.
 */
final class ComplianceRecording extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'compliance_recordings';

    protected $fillable = [
        'tenant_id',
        'call_id',
        'deal_id',
        'user_id',
        'tcpa_consent_captured',
        'recording_disclosure_made',
        'no_guarantee_disclosure_made',
        'refund_policy_disclosure_made',
        'total_fee_stated_clearly',
        'disclosure_timestamps',
        'compliance_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'tcpa_consent_captured' => 'boolean',
            'recording_disclosure_made' => 'boolean',
            'no_guarantee_disclosure_made' => 'boolean',
            'refund_policy_disclosure_made' => 'boolean',
            'total_fee_stated_clearly' => 'boolean',
            'disclosure_timestamps' => 'array',
            'compliance_status' => ComplianceStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopePendingReview(Builder $q): Builder
    {
        return $q->where('compliance_status', ComplianceStatus::PendingReview->value);
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->whereIn('compliance_status', [
            ComplianceStatus::Failed->value,
            ComplianceStatus::FlaggedForAudit->value,
        ]);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    /**
     * The agreement cannot proceed to listing distribution until every
     * mandatory disclosure marker is true.
     */
    public function allDisclosuresCaptured(): bool
    {
        return $this->tcpa_consent_captured
            && $this->recording_disclosure_made
            && $this->no_guarantee_disclosure_made
            && $this->refund_policy_disclosure_made
            && $this->total_fee_stated_clearly;
    }

    /**
     * Names of disclosures still missing — for the reviewer UI.
     *
     * @return list<string>
     */
    public function missingDisclosures(): array
    {
        $missing = [];
        if (! $this->tcpa_consent_captured) {
            $missing[] = 'TCPA consent';
        }
        if (! $this->recording_disclosure_made) {
            $missing[] = 'Recording disclosure';
        }
        if (! $this->no_guarantee_disclosure_made) {
            $missing[] = 'No-guarantee disclosure';
        }
        if (! $this->refund_policy_disclosure_made) {
            $missing[] = 'Refund policy';
        }
        if (! $this->total_fee_stated_clearly) {
            $missing[] = 'Total fee stated';
        }

        return $missing;
    }
}
