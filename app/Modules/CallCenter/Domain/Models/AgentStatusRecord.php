<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\AgentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent agent presence row — single row per agent (unique constraint
 * on agent_id), updated in place as status transitions.
 *
 * Postgres holds the source-of-truth durable state. Redis (via
 * AgentPresenceService) holds the hot path: a hash keyed by agent_id with
 * status, last_heartbeat_at, current_call_id. The Redis layer is the one
 * the predictive dialer reads on every pacing tick. Postgres survives
 * a Redis flush.
 *
 * NB: this model is named AgentStatusRecord (not AgentStatus) to avoid
 * shadowing the AgentStatus enum in App\Support\Enums.
 */
final class AgentStatusRecord extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'agent_statuses';

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'status',
        'previous_status',
        'current_call_id',
        'current_session_id',
        'status_changed_at',
        'last_heartbeat_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'status_changed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
