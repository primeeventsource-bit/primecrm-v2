<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAvailability>
 */
final class InventoryAvailabilityFactory extends Factory
{
    protected $model = InventoryAvailability::class;

    public function definition(): array
    {
        $checkIn = $this->faker->dateTimeBetween('+1 week', '+6 months');
        $checkOut = (clone $checkIn)->modify('+7 days');

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'resort_id' => ResortFactory::new(),
            'inventory_unit_id' => InventoryUnitFactory::new(),
            'check_in_date' => $checkIn->format('Y-m-d'),
            'check_out_date' => $checkOut->format('Y-m-d'),
            'nights' => 7,
            'status' => InventoryAvailability::STATUS_AVAILABLE,
            'base_price' => 1500.00,
            'current_price' => 1499.00,
            'currency' => 'USD',
        ];
    }

    public function held(): self
    {
        return $this->state(fn () => ['status' => InventoryAvailability::STATUS_HELD]);
    }

    public function booked(): self
    {
        return $this->state(fn () => ['status' => InventoryAvailability::STATUS_BOOKED]);
    }
}
