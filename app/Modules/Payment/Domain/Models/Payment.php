<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A money movement.
 *
 * Types:
 *   charge       — initial sale
 *   deposit      — partial up-front payment
 *   refund       — refund to the cardholder; parent_payment_id points
 *                  at the original charge
 *   chargeback   — disputed by the cardholder; parent_payment_id points
 *                  at the original charge. Triggers commission reversal.
 *
 * `cleared_at` is the commission trigger. We don't pay agents on
 * authorized-only payments — the funds need to settle first. Stripe's
 * `charge.succeeded` populates this; ACH `funds_available` also does.
 */
final class Payment extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'payments';

    public const TYPE_CHARGE = 'charge';
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_CHARGEBACK = 'chargeback';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_CHARGEBACK = 'chargeback';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'deal_id',
        'lead_id',
        'processed_by_id',
        'provider',
        'provider_payment_id',
        'provider_customer_id',
        'payment_method',
        'card_last_four',
        'card_brand',
        'amount',
        'currency',
        'type',
        'status',
        'parent_payment_id',
        'provider_metadata',
        'failure_code',
        'failure_reason',
        'authorized_at',
        'captured_at',
        'cleared_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_metadata' => 'array',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'cleared_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    public function scopeCleared(Builder $query): Builder
    {
        return $query->whereNotNull('cleared_at')->where('status', self::STATUS_SUCCEEDED);
    }

    public function scopeForBooking(Builder $query, string $bookingId): Builder
    {
        return $query->where('booking_id', $bookingId);
    }

    public function isCharge(): bool
    {
        return $this->type === self::TYPE_CHARGE || $this->type === self::TYPE_DEPOSIT;
    }

    public function isReversal(): bool
    {
        return $this->type === self::TYPE_REFUND || $this->type === self::TYPE_CHARGEBACK;
    }

    public function isCleared(): bool
    {
        return $this->cleared_at !== null && $this->status === self::STATUS_SUCCEEDED;
    }
}
