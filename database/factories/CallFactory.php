<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Support\Enums\CallDirection;
use App\Support\Enums\CallStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
final class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        $to = '+1'.$this->faker->numerify('##########');

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'lead_id' => null,
            'agent_id' => null,
            'dial_session_id' => null,
            'campaign_id' => null,
            'provider' => 'twilio',
            'provider_call_sid' => 'CA'.$this->faker->lexify(str_repeat('?', 32)),
            'from_number' => '+15555550000',
            'to_number' => $to,
            'direction' => CallDirection::Outbound->value,
            'status' => CallStatus::Queued->value,
            'queued_at' => now(),
            'recording_status' => 'not_recorded',
            'transcription_status' => 'not_started',
        ];
    }

    public function ringing(): self
    {
        return $this->state(fn () => [
            'status' => CallStatus::Ringing->value,
            'initiated_at' => now()->subSeconds(2),
        ]);
    }

    public function answered(): self
    {
        return $this->state(fn () => [
            'status' => CallStatus::InProgress->value,
            'initiated_at' => now()->subSeconds(8),
            'answered_at' => now()->subSeconds(2),
            'ring_seconds' => 6,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn () => [
            'status' => CallStatus::Completed->value,
            'initiated_at' => now()->subMinutes(5),
            'answered_at' => now()->subMinutes(4),
            'ended_at' => now()->subMinutes(1),
            'ring_seconds' => 6,
            'duration_seconds' => 180,
        ]);
    }

    public function abandoned(): self
    {
        return $this->state(fn () => [
            'status' => CallStatus::Canceled->value,
            'substatus' => 'abandoned',
            'initiated_at' => now()->subMinutes(2),
            'answered_at' => now()->subMinutes(1),
            'ended_at' => now()->subSeconds(58),
        ]);
    }
}
