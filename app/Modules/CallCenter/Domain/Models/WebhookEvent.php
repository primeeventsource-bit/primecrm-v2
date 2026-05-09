<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotent provider webhook log.
 *
 * NOT TenantScoped — most provider webhooks (Twilio status callbacks,
 * Stripe events) arrive without a tenant context resolved. The processor
 * derives the tenant from the payload (CallSid → call → tenant_id) and
 * either populates `tenant_id` after processing, or leaves it null if
 * the event maps to no known tenant (junk webhook, dropped).
 *
 * The unique (provider, external_id) constraint on the table is the
 * foundational guarantee. Even if our application code has a bug that
 * tries to process an event twice, the DB rejects the second insert.
 */
final class WebhookEvent extends Model
{
    use HasUuid;

    protected $table = 'webhook_events';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    protected $fillable = [
        'tenant_id',
        'provider',
        'event_type',
        'external_id',
        'payload',
        'headers',
        'status',
        'attempts',
        'last_error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_PROCESSING]);
    }
}
