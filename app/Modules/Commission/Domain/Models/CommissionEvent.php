<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only commission event log. NEVER update or delete rows here.
 *
 * Every business event that affects commissions writes one row:
 *   payment.cleared, payment.refunded, payment.chargeback,
 *   deal.closed_won, deal.cancelled, manual.adjustment, etc
 *
 * The `idempotency_key` is unique across the table. Listeners that map
 * domain events to commission events compute the key from the source —
 * `payment.cleared:{payment_id}`, `chargeback:{chargeback_id}` — so re-
 * dispatch of the same domain event no-ops at the DB level.
 */
final class CommissionEvent extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'source_entity_type',
        'source_entity_id',
        'payload',
        'idempotency_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function calculations(): HasMany
    {
        return $this->hasMany(CommissionCalculation::class);
    }

    public function scopeOfType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }
}
