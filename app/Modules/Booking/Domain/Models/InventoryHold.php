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

/**
 * A temporary reservation on an inventory_availability row.
 *
 * Holds expire automatically via expires_at. ExpireInventoryHoldsJob
 * (scheduled every minute) sweeps stale rows and:
 *   1. Sets released_at and release_reason='expired' on the hold
 *   2. Flips inventory_availability.status back to 'available'
 *   3. Clears inventory_availability.current_hold_id
 *
 * Holds are voluntarily released too (release_reason='converted' when
 * promoted to a booking, 'agent_released' when an agent abandons).
 */
final class InventoryHold extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'inventory_holds';

    public const REASON_EXPIRED = 'expired';
    public const REASON_CONVERTED = 'converted';
    public const REASON_AGENT_RELEASED = 'agent_released';

    protected $fillable = [
        'tenant_id',
        'inventory_availability_id',
        'lead_id',
        'deal_id',
        'held_by_id',
        'expires_at',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(InventoryAvailability::class, 'inventory_availability_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNull('released_at')
            ->where('expires_at', '<=', now());
    }

    public function isActive(): bool
    {
        return $this->released_at === null && $this->expires_at?->isFuture();
    }
}
