<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a single inbound partner webhook attempt.
 *
 * Recorded by PartnerWebhookEventLogger from the inbound controller —
 * every signature check, validation pass/fail, dedup hit, and success
 * lands one row here. Drives the partner-site card's "Recent activity"
 * feed.
 *
 * `$timestamps` is disabled because we only stamp created_at (events
 * never mutate; an updated_at would always equal created_at).
 */
final class PartnerWebhookEvent extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'partner_webhook_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'partner_site_id',
        'kind',
        'http_status',
        'signature_valid',
        'external_inquiry_id',
        'external_booking_id',
        'related_id',
        'error_message',
        'request_ip',
        'user_agent',
        'payload_size_bytes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'http_status' => 'integer',
            'payload_size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function partnerSite(): BelongsTo
    {
        return $this->belongsTo(PartnerSite::class);
    }
}
