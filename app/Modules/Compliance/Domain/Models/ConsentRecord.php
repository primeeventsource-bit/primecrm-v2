<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TCPA express consent record. The legal artifact justifying any
 * automated/predictive dial to a wireless number. Append-only by design —
 * a revocation is a separate write (revoked_at), never a delete.
 */
final class ConsentRecord extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'consent_records';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'phone_hash',
        'phone',
        'consent_type',
        'source',
        'source_url',
        'source_ip',
        'user_agent',
        'recording_url',
        'consent_text_snapshot',
        'consented_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'consent_type' => ConsentType::class,
            'consent_text_snapshot' => 'array',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Active = not revoked. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeForPhone(Builder $query, string $phoneHash): Builder
    {
        return $query->where('phone_hash', $phoneHash);
    }

    public function scopeOfType(Builder $query, ConsentType $type): Builder
    {
        return $query->where('consent_type', $type->value);
    }
}
