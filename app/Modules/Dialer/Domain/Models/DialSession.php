<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\DialerMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent's dial session — analogous to a "shift on the dialer".
 *
 * Counters (leads_processed, calls_initiated, calls_connected,
 * calls_abandoned, total_talk_seconds) are updated incrementally as
 * the dialer ticks. They drive the live agent dashboard and feed back
 * into pacing math.
 *
 * State machine:
 *   active   ←—————┐
 *     ↓             │ resume
 *   paused  ─────  ─┘
 *     ↓
 *   stopped (terminal)
 */
final class DialSession extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'dial_sessions';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'campaign_id',
        'mode',
        'status',
        'leads_processed',
        'calls_initiated',
        'calls_connected',
        'calls_abandoned',
        'total_talk_seconds',
        'total_wrap_seconds',
        'settings',
        'started_at',
        'paused_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => DialerMode::class,
            'settings' => 'array',
            'leads_processed' => 'integer',
            'calls_initiated' => 'integer',
            'calls_connected' => 'integer',
            'calls_abandoned' => 'integer',
            'total_talk_seconds' => 'integer',
            'total_wrap_seconds' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function dialerMode(): DialerMode
    {
        return $this->mode instanceof DialerMode ? $this->mode : DialerMode::from((string) $this->mode);
    }
}
