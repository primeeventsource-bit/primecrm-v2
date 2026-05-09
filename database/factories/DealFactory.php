<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Enums\DealStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
final class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        $total = $this->faker->numberBetween(1500, 25000);

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'lead_id' => LeadFactory::new(),
            'agent_id' => UserFactory::new()->closer(),
            'fronter_id' => null,
            'additional_closer_ids' => null,
            'stage' => DealStage::New->value,
            'stage_changed_at' => now(),
            'total_value' => $total,
            'snr_amount' => 0,
            'vd_amount' => 0,
            'payable_amount' => $total,
            'currency' => 'USD',
            'pitch_data' => null,
        ];
    }

    public function won(): self
    {
        return $this->state(fn () => [
            'stage' => DealStage::ClosedWon->value,
            'closed_at' => now(),
            'stage_changed_at' => now(),
        ]);
    }
}
