<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Listing\Domain\Enums\PropertyOwnershipType;
use App\Modules\Listing\Domain\Enums\PropertySeason;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The timeshare itself — what the owner owns.
 *
 * One property → many listings over time (different weeks, different
 * years). Property carries the durable facts (resort, week, ownership
 * type); listings carry the per-marketing-cycle facts (price, dates,
 * partner-site distribution).
 */
final class Property extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'properties';

    protected $fillable = [
        'tenant_id',
        'owner_id',
        'resort_name',
        'resort_brand',
        'location_city',
        'location_state',
        'location_country',
        'unit_number',
        'bedrooms',
        'sleeps',
        'view_type',
        'ownership_type',
        'points_balance',
        'fixed_week_number',
        'season',
        'ownership_verified',
        'ownership_verified_at',
        'ownership_verified_by',
        'verification_document_path',
        'rental_allowed_by_resort',
    ];

    protected function casts(): array
    {
        return [
            'ownership_type' => PropertyOwnershipType::class,
            'season' => PropertySeason::class,
            'bedrooms' => 'integer',
            'sleeps' => 'integer',
            'points_balance' => 'integer',
            'fixed_week_number' => 'integer',
            'ownership_verified' => 'boolean',
            'ownership_verified_at' => 'datetime',
            'rental_allowed_by_resort' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'owner_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ownership_verified_by');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'property_id');
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('ownership_verified', true);
    }

    public function scopeRentable(Builder $q): Builder
    {
        return $q->where('ownership_verified', true)
            ->where('rental_allowed_by_resort', true);
    }

    public function scopeForOwner(Builder $q, string $ownerId): Builder
    {
        return $q->where('owner_id', $ownerId);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function locationLabel(): string
    {
        return trim("{$this->location_city}, {$this->location_state}");
    }

    public function isReadyToList(): bool
    {
        return $this->ownership_verified && $this->rental_allowed_by_resort;
    }
}
