<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\Services\PhoneNormalizer;
use App\Core\Shared\TenantContext;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadPriority;
use App\Support\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        $phone = $this->faker->numerify('+1##########');
        $hash = hash('sha256', $phone);

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $phone,
            'phone_hash' => $hash,
            'country' => 'US',
            'state' => $this->faker->stateAbbr(),
            'city' => $this->faker->city(),
            'postal_code' => $this->faker->postcode(),
            'timezone' => 'America/New_York',
            'status' => LeadStatus::New->value,
            'priority' => LeadPriority::Normal->value,
            'score' => 0,
            'source' => 'csv_import',
            'is_on_dnc' => false,
            'has_express_consent' => false,
            'contact_attempts' => 0,
        ];
    }

    public function withPhone(string $phone): self
    {
        $normalizer = app(PhoneNormalizer::class);
        $normalized = $normalizer->normalizeAndHash($phone);
        if ($normalized === null) {
            return $this->state(fn () => ['phone' => $phone, 'phone_hash' => hash('sha256', $phone)]);
        }
        [$e164, $hash] = $normalized;

        return $this->state(fn () => ['phone' => $e164, 'phone_hash' => $hash]);
    }

    public function hot(): self
    {
        return $this->state(fn () => ['priority' => LeadPriority::Hot->value]);
    }

    public function withConsent(): self
    {
        return $this->state(fn () => [
            'has_express_consent' => true,
            'consent_at' => now()->subDay(),
        ]);
    }

    public function onDnc(): self
    {
        return $this->state(fn () => ['is_on_dnc' => true]);
    }

    public function closed(): self
    {
        return $this->state(fn () => ['status' => LeadStatus::ClosedWon->value]);
    }
}
