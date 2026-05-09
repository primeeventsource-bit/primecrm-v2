<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (unit, check_in_date) — a bookable week.
 *
 * The CRITICAL invariant on this table is the partial unique index
 * (inventory_unit_id, check_in_date) WHERE status IN ('available',
 * 'held', 'booked'). At most one of those rows can exist per unit/date
 * combination at any time. Two operators cannot simultaneously hold or
 * book the same week — the second INSERT/UPDATE attempt raises a
 * unique-constraint violation that the HoldService catches and reports
 * as "unit_no_longer_available".
 *
 * Status transitions:
 *   available → held → booked (forward path)
 *   available → blocked / maintenance (operator action)
 *   held → available (release)
 *   booked → cancelled (refund/cancel — but the row stays "cancelled"
 *           outside the unique index; a fresh "available" row is then
 *           inserted to allow rebooking)
 */
final class InventoryAvailability extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'inventory_availability';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_HELD = 'held';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'resort_id',
        'inventory_unit_id',
        'check_in_date',
        'check_out_date',
        'nights',
        'status',
        'base_price',
        'current_price',
        'currency',
        'current_hold_id',
        'booking_id',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'nights' => 'integer',
            'base_price' => 'decimal:2',
            'current_price' => 'decimal:2',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'inventory_unit_id');
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HELD);
    }

    public function scopeForResort(Builder $query, string $resortId): Builder
    {
        return $query->where('resort_id', $resortId);
    }

    public function scopeBetween(Builder $query, string $checkIn, string $checkOut): Builder
    {
        return $query
            ->where('check_in_date', '>=', $checkIn)
            ->where('check_in_date', '<=', $checkOut);
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
