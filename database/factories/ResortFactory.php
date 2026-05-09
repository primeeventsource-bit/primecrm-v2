<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Resort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Resort>
 */
final class ResortFactory extends Factory
{
    protected $model = Resort::class;

    public function definition(): array
    {
        $name = $this->faker->company().' Resort';

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'name' => $name,
            'brand' => $this->faker->randomElement(['Westgate', 'Wyndham', 'Disney Vacation Club']),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'country' => 'US',
            'state' => $this->faker->stateAbbr(),
            'city' => $this->faker->city(),
            'timezone' => 'America/New_York',
            'hold_ttl_minutes' => 30,
            'active' => true,
        ];
    }
}
