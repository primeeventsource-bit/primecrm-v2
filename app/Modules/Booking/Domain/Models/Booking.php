<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A confirmed booking — the contract between the customer and the resort.
 *
 * Status flow:
 *   confirmed → paid → completed (after stay)
 *   confirmed → cancelled → refunded
 *
 * `confirmation_number` is unique (DB constraint). Generated from a
 * deterministic-but-collision-resistant pattern at confirm time.
 */
final class Booking extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'bookings';

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'deal_id',
        'inventory_availability_id',
        'agent_id',
        'status',
        'total_price',
        'paid_amount',
        'currency',
        'check_in_date',
        'check_out_date',
        'guest_details',
        'confirmation_number',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        // Listing-domain renter side (added via augment_bookings_for_rentals).
        'listing_id',
        'inquiry_id',
        'renter_name',
        'renter_email',
        'renter_phone',
        'owner_payout',
        'our_commission',
        'owner_notified_at',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'guest_details' => 'array',
            'total_price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            // Listing-domain renter side
            'owner_payout' => 'decimal:2',
            'our_commission' => 'decimal:2',
            'owner_notified_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(InventoryAvailability::class, 'inventory_availability_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Listing this booking rented (timeshare-rental domain).
     * Nullable for legacy bookings that pre-date the listing module.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Listing\Domain\Models\Listing::class, 'listing_id');
    }

    /**
     * Inquiry that converted to this booking, if any.
     */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Listing\Domain\Models\RentalInquiry::class, 'inquiry_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_PAID])
            ->where('check_in_date', '>=', now()->toDateString());
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);
    }
}
