<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Contract extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'contracts';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'deal_id',
        'lead_id',
        'template_key',
        'status',
        'provider',
        'provider_envelope_id',
        's3_path',
        'signed_pdf_s3_path',
        'signers',
        'sent_at',
        'viewed_at',
        'signed_at',
        'expires_at',
        'audit_trail',
    ];

    protected function casts(): array
    {
        return [
            'signers' => 'array',
            'audit_trail' => 'array',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED && $this->signed_at !== null;
    }
}
