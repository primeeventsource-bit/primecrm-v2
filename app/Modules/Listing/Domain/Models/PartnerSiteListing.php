<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Modules\Listing\Domain\Enums\PartnerSiteListingStatus;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Junction row: one listing pushed to one partner site.
 *
 * Carries the per-site lifecycle (pending / live / paused / rejected)
 * and the per-site engagement counters (views / inquiries). The
 * (listing_id, partner_site_id) unique constraint prevents accidental
 * double-pushes.
 */
final class PartnerSiteListing extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'partner_site_listings';

    protected $fillable = [
        'tenant_id',
        'listing_id',
        'partner_site_id',
        'external_listing_id',
        'external_url',
        'status',
        'rejection_reason',
        'view_count',
        'inquiry_count',
        'pushed_at',
        'went_live_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PartnerSiteListingStatus::class,
            'view_count' => 'integer',
            'inquiry_count' => 'integer',
            'pushed_at' => 'datetime',
            'went_live_at' => 'datetime',
            'last_synced_at' => 'datetime',
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

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', PartnerSiteListingStatus::Live->value);
    }

    public function scopeNeedsAttention(Builder $q): Builder
    {
        return $q->whereIn('status', [
            PartnerSiteListingStatus::Rejected->value,
            PartnerSiteListingStatus::Paused->value,
        ]);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function timeToLiveSeconds(): ?int
    {
        if (! $this->went_live_at || ! $this->pushed_at) {
            return null;
        }

        return $this->went_live_at->diffInSeconds($this->pushed_at);
    }
}
