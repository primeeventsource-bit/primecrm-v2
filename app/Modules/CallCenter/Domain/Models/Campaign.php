<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\DialerMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A dialing campaign — the unit of pacing.
 *
 * Pacing parameters here are the *upper bounds* the predictive engine
 * works within. The engine adapts dial rate based on observed connect
 * rate and abandon rate, but it never exceeds these:
 *
 *   - target_abandon_rate (default 0.03)  — FCC 3% over rolling 30 days
 *   - safety_factor (default 1.0)         — multiplier on the dial rate
 *   - max_attempts_per_lead (default 6)   — across the campaign's run
 *   - min_hours_between_attempts (4)      — TCPA cooldown enforced at gate too
 *   - earliest/latest_call_local          — TCPA window per state, again
 *                                           enforced by the calling-window
 *                                           guardrail; this is the campaign's
 *                                           tighter override
 */
final class Campaign extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'campaigns';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'dialer_mode',
        'target_abandon_rate',
        'safety_factor',
        'max_attempts_per_lead',
        'min_hours_between_attempts',
        'earliest_call_local',
        'latest_call_local',
        'script_template',
        'metadata',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'dialer_mode' => DialerMode::class,
            'target_abandon_rate' => 'float',
            'safety_factor' => 'float',
            'max_attempts_per_lead' => 'integer',
            'min_hours_between_attempts' => 'integer',
            'script_template' => 'array',
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function dialerMode(): DialerMode
    {
        return $this->dialer_mode instanceof DialerMode
            ? $this->dialer_mode
            : DialerMode::from((string) $this->dialer_mode);
    }
}
