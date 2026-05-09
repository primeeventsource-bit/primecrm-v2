<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Compliance\Domain\Models\ConsentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRecord>
 */
final class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    public function definition(): array
    {
        $phone = $this->faker->numerify('+1##########');

        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'consent_type' => ConsentType::Autodialer->value,
            'source' => 'web_form',
            'source_url' => 'https://example.com/quote',
            'source_ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'consent_text_snapshot' => ['version' => '1.0', 'text' => 'I consent…'],
            'consented_at' => now()->subDay(),
        ];
    }

    public function forPhone(string $e164): self
    {
        return $this->state(fn () => [
            'phone' => $e164,
            'phone_hash' => hash('sha256', $e164),
        ]);
    }

    public function ofType(ConsentType $type): self
    {
        return $this->state(fn () => ['consent_type' => $type->value]);
    }

    public function revoked(): self
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
            'revocation_reason' => 'User request',
        ]);
    }
}
