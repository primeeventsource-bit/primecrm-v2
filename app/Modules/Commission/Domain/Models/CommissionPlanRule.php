<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule on a commission plan.
 *
 * `rule_type`:
 *   flat        — `config.amount` paid once per matching event
 *   percentage  — `config.rate` × `payload.{config.base_field}`
 *   tiered      — `config.brackets`: [{up_to: number, rate: float}, ...]
 *                 Brackets are evaluated against `payload.{config.base_field}`;
 *                 the rule applies the tier's rate (NOT a marginal sum) —
 *                 simpler to explain to agents. Marginal mode toggled via
 *                 `config.marginal=true`.
 *   bonus       — handled at payout time, not per-event (period rollup)
 *   override    — supervisor/manager override above someone else's commission;
 *                 `config.override_rate` × original calculation.amount
 *
 * `trigger_event`: matches CommissionEvent.event_type.
 *
 * `role`: who gets it — closer, fronter, supervisor, qa, override.
 *         The recipient resolution depends on the source entity:
 *         for a deal, role=closer → deal.agent_id; fronter → deal.fronter_id.
 */
final class CommissionPlanRule extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'commission_plan_rules';

    public const TYPE_FLAT = 'flat';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_TIERED = 'tiered';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_OVERRIDE = 'override';

    public const ROLE_CLOSER = 'closer';
    public const ROLE_FRONTER = 'fronter';
    public const ROLE_SUPERVISOR = 'supervisor';
    public const ROLE_QA = 'qa';
    public const ROLE_OVERRIDE = 'override';

    protected $fillable = [
        'tenant_id',
        'commission_plan_id',
        'role',
        'rule_type',
        'trigger_event',
        'config',
        'priority',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeForEvent(Builder $query, string $eventType): Builder
    {
        return $query->where('trigger_event', $eventType);
    }
}
