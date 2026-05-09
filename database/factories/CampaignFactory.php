<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Support\Enums\DialerMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'name' => $this->faker->words(3, true),
            'status' => Campaign::STATUS_ACTIVE,
            'dialer_mode' => DialerMode::Predictive->value,
            'target_abandon_rate' => 0.03,
            'safety_factor' => 1.0,
            'max_attempts_per_lead' => 6,
            'min_hours_between_attempts' => 4,
            'earliest_call_local' => '08:00:00',
            'latest_call_local' => '21:00:00',
        ];
    }

    public function predictive(): self
    {
        return $this->state(fn () => ['dialer_mode' => DialerMode::Predictive->value]);
    }

    public function progressive(): self
    {
        return $this->state(fn () => ['dialer_mode' => DialerMode::Progressive->value]);
    }

    public function manual(): self
    {
        return $this->state(fn () => ['dialer_mode' => DialerMode::Manual->value]);
    }

    public function safetyFactor(float $factor): self
    {
        return $this->state(fn () => ['safety_factor' => $factor]);
    }
}
