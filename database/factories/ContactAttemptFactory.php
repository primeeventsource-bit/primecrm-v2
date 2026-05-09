<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Models\ContactAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAttempt>
 */
final class ContactAttemptFactory extends Factory
{
    protected $model = ContactAttempt::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'phone_hash' => hash('sha256', '+1'.$this->faker->numerify('##########')),
            'attempt_type' => ContactAttempt::ATTEMPT_OUTBOUND_CALL,
            'outcome' => 'no_answer',
            'attempted_at' => now()->subMinutes($this->faker->numberBetween(1, 60)),
        ];
    }

    public function forPhoneHash(string $hash): self
    {
        return $this->state(fn () => ['phone_hash' => $hash]);
    }

    public function attemptedAt(\DateTimeInterface $time): self
    {
        return $this->state(fn () => ['attempted_at' => $time]);
    }
}
