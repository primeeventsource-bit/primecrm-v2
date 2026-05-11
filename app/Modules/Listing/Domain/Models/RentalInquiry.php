<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Modules\Listing\Domain\Enums\RentalInquiryStatus;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A potential renter expressed interest in a listing.
 *
 * Inquiries arrive via partner sites (or direct), get routed to an
 * agent, and either convert to a booking or are lost. The dashboard
 * tracks inquiries-unanswered-over-4h as a service-quality signal.
 */
final class RentalInquiry extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'rental_inquiries';

    protected $fillable = [
        'tenant_id',
        'listing_id',
        'partner_site_id',
        'renter_name',
        'renter_email',
        'renter_phone',
        'requested_check_in',
        'requested_check_out',
        'offered_amount',
        'message',
        'status',
        'handled_by',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RentalInquiryStatus::class,
            'requested_check_in' => 'date',
            'requested_check_out' => 'date',
            'offered_amount' => 'decimal:2',
            'responded_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function partnerSite(): BelongsTo
    {
        return $this->belongsTo(PartnerSite::class, 'partner_site_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            RentalInquiryStatus::New->value,
            RentalInquiryStatus::Responded->value,
            RentalInquiryStatus::Negotiating->value,
        ]);
    }

    public function scopeUnanswered(Builder $q): Builder
    {
        return $q->whereNull('responded_at');
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function isStale(): bool
    {
        if ($this->responded_at !== null) {
            return false;
        }

        return $this->created_at !== null
            && $this->created_at->diffInHours(now()) >= 4;
    }
}
