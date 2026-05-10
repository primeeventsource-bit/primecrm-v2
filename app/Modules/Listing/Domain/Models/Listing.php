<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A specific marketed offering: one or more weeks from a property,
 * pushed to one or more partner sites.
 *
 * The deal_id back-references the listing-fee agreement that paid for
 * this listing's existence. Without a paid deal, no listing.
 *
 * Per-site distribution lives on partner_site_listings; the canonical
 * listing record here is partner-agnostic (price, dates, photos).
 */
final class Listing extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'listings';

    protected $fillable = [
        'tenant_id',
        'property_id',
        'deal_id',
        'check_in_date',
        'check_out_date',
        'asking_price',
        'reserve_price',
        'owner_payout',
        'our_commission_pct',
        'status',
        'went_live_at',
        'expires_at',
        'marketing_description',
        'photos',
    ];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'asking_price' => 'decimal:2',
            'reserve_price' => 'decimal:2',
            'owner_payout' => 'decimal:2',
            'our_commission_pct' => 'decimal:2',
            'went_live_at' => 'datetime',
            'expires_at' => 'datetime',
            'photos' => 'array',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function partnerSiteListings(): HasMany
    {
        return $this->hasMany(PartnerSiteListing::class, 'listing_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(RentalInquiry::class, 'listing_id');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('status', [
            ListingStatus::Live->value,
            ListingStatus::InquiryReceived->value,
            ListingStatus::PendingBooking->value,
        ]);
    }

    public function scopeExpiringSoon(Builder $q, int $days = 14): Builder
    {
        return $q->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days));
    }

    public function scopeForProperty(Builder $q, string $propertyId): Builder
    {
        return $q->where('property_id', $propertyId);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    /**
     * How many days is the listing window? Used by the "Time-to-live"
     * dashboard metric.
     */
    public function timeToLiveSeconds(): ?int
    {
        if (! $this->went_live_at || ! $this->created_at) {
            return null;
        }

        return $this->went_live_at->diffInSeconds($this->created_at);
    }
}
