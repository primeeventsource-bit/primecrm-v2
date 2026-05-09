<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Commission\Domain\Models\CommissionAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionAssignment>
 */
final class CommissionAssignmentFactory extends Factory
{
    protected $model = CommissionAssignment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'user_id' => UserFactory::new()->closer(),
            'commission_plan_id' => CommissionPlanFactory::new(),
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
        ];
    }
}
