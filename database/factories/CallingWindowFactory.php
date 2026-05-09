<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Compliance\Domain\Models\CallingWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallingWindow>
 */
final class CallingWindowFactory extends Factory
{
    protected $model = CallingWindow::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null, // global federal default
            'jurisdiction' => 'US-FED',
            'earliest_local' => '08:00:00',
            'latest_local' => '21:00:00',
            'blocked_weekdays' => [],
            'blocked_dates' => [],
            'active' => true,
        ];
    }

    public function forJurisdiction(string $jurisdiction): self
    {
        return $this->state(fn () => ['jurisdiction' => $jurisdiction]);
    }

    public function window(string $earliest, string $latest): self
    {
        return $this->state(fn () => [
            'earliest_local' => $earliest,
            'latest_local' => $latest,
        ]);
    }
}
