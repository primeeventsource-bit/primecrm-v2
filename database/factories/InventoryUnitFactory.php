<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\InventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryUnit>
 */
final class InventoryUnitFactory extends Factory
{
    protected $model = InventoryUnit::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'resort_id' => ResortFactory::new(),
            'unit_type' => $this->faker->randomElement(['studio', '1br', '2br', '3br']),
            'sleeps' => $this->faker->numberBetween(2, 8),
            'features' => [],
            'active' => true,
        ];
    }
}
