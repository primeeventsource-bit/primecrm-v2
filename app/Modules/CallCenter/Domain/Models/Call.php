<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\CallDirection;
use App\Support\Enums\CallDisposition;
use App\Support\Enums\CallStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Call aggregate.
 *
 * The `calls` row is the canonical record of a single dialing attempt;
 * the `call_events` rows are the append-only state log that produced it.
 * Mutating the row directly is fine — the events log is the audit trail
 * and webhook idempotency mechanism, not the source of truth for current
 * state.
 *
 * Twilio's CallSid lands in `provider_call_sid` and is the cross-system
 * correlation key. Any webhook for a sid we don't already have a row for
 * gets dropped (it's either a webhook for a stale call we already cleaned
 * up, or — in dev — a stray callback from a different account).
 */
final class Call extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'calls';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'agent_id',
        'dial_session_id',
        'campaign_id',
        'provider',
        'provider_call_sid',
        'provider_parent_sid',
        'from_number',
        'to_number',
        'direction',
        'status',
        'substatus',
        'disposition',
        'disposition_notes',
        'queued_at',
        'initiated_at',
        'answered_at',
        'ended_at',
        'ring_seconds',
        'duration_seconds',
        'wrap_up_seconds',
        'recording_status',
        'recording_provider_sid',
        'recording_url',
        'recording_s3_path',
        'recording_duration_seconds',
        'recording_paused_at',
        'transcription_status',
        'transcription_text',
        'sentiment',
        'sentiment_timeline',
        'provider_cost',
        'provider_cost_currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'status' => CallStatus::class,
            'disposition' => CallDisposition::class,
            'queued_at' => 'datetime',
            'initiated_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'recording_paused_at' => 'datetime',
            'sentiment_timeline' => 'array',
            'metadata' => 'array',
            'ring_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'wrap_up_seconds' => 'integer',
            'recording_duration_seconds' => 'integer',
            'provider_cost' => 'decimal:4',
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

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CallStatus::Queued->value,
            CallStatus::Initiated->value,
            CallStatus::Ringing->value,
            CallStatus::InProgress->value,
        ]);
    }

    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeBySid(Builder $query, string $sid): Builder
    {
        return $query->where('provider_call_sid', $sid);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function isLive(): bool
    {
        return $this->status instanceof CallStatus && $this->status->isLive();
    }

    public function isTerminal(): bool
    {
        return $this->status instanceof CallStatus && $this->status->isTerminal();
    }

    public function connected(): bool
    {
        return $this->answered_at !== null;
    }
}
