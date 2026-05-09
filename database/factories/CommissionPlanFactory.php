<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionPlan>
 */
final class CommissionPlanFactory extends Factory
{
    protected $model = CommissionPlan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'name' => $this->faker->words(2, true).' Plan',
            'description' => null,
            'active' => true,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
        ];
    }
}
