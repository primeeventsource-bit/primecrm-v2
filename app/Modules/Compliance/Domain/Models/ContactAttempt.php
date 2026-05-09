<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per outbound contact attempt to a phone (or send to email/SMS).
 *
 * Drives frequency-cap enforcement in the guardrail. Written by the dialer
 * (Response 3) immediately when a call is initiated — BEFORE the call
 * connects — so even abandoned dials count toward the cap. Without that,
 * a misbehaving dialer could blow past the daily cap by initiating
 * thousands of unanswered ringings.
 */
final class ContactAttempt extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'contact_attempts';

    public const ATTEMPT_OUTBOUND_CALL = 'outbound_call';
    public const ATTEMPT_SMS = 'sms';
    public const ATTEMPT_EMAIL = 'email';

    protected $fillable = [
        'tenant_id',
        'phone_hash',
        'lead_id',
        'agent_id',
        'call_id',
        'attempt_type',
        'outcome',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function scopeForPhone(Builder $query, string $phoneHash): Builder
    {
        return $query->where('phone_hash', $phoneHash);
    }

    public function scopeOutboundCalls(Builder $query): Builder
    {
        return $query->where('attempt_type', self::ATTEMPT_OUTBOUND_CALL);
    }
}
