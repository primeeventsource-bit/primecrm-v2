<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionPlanRule>
 */
final class CommissionPlanRuleFactory extends Factory
{
    protected $model = CommissionPlanRule::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'commission_plan_id' => CommissionPlanFactory::new(),
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
            'trigger_event' => 'payment.cleared',
            'config' => ['rate' => 0.10, 'base_field' => 'amount'],
            'priority' => 0,
            'active' => true,
        ];
    }

    public function flat(float $amount): self
    {
        return $this->state(fn () => [
            'rule_type' => CommissionPlanRule::TYPE_FLAT,
            'config' => ['amount' => $amount],
        ]);
    }

    public function percentage(float $rate, string $baseField = 'amount'): self
    {
        return $this->state(fn () => [
            'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
            'config' => ['rate' => $rate, 'base_field' => $baseField],
        ]);
    }

    public function tiered(array $brackets, bool $marginal = false, string $baseField = 'amount'): self
    {
        return $this->state(fn () => [
            'rule_type' => CommissionPlanRule::TYPE_TIERED,
            'config' => ['brackets' => $brackets, 'marginal' => $marginal, 'base_field' => $baseField],
        ]);
    }

    public function onEvent(string $eventType): self
    {
        return $this->state(fn () => ['trigger_event' => $eventType]);
    }

    public function role(string $role): self
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
