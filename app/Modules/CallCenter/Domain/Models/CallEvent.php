<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only event log for a Call's lifecycle.
 *
 * Every state transition writes one row here — initiated, ringing, answered,
 * ended, recording_started, recording_completed, transferred, agent_joined,
 * supervisor_whisper, etc. The `idempotency_key` (unique across the table)
 * is what protects the dialer from Twilio's "we'll retry this webhook three
 * times" behavior. The first write wins; subsequent writes either raise
 * unique-constraint violations (caught and treated as "already processed")
 * or are filtered by the WebhookEventStore's pre-check.
 *
 * Reconstructing a call's history is a single index scan on (call_id,
 * occurred_at). No need for event sourcing infrastructure.
 */
final class CallEvent extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'call_events';

    public $timestamps = false; // we have created_at default useCurrent + occurred_at

    protected $fillable = [
        'tenant_id',
        'call_id',
        'event_type',
        'source',
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

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
