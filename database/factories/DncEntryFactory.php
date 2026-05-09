<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Models\DncEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DncEntry>
 */
final class DncEntryFactory extends Factory
{
    protected $model = DncEntry::class;

    public function definition(): array
    {
        $phone = $this->faker->numerify('+1##########');

        return [
            'tenant_id' => null, // global by default; use ->forTenant() to scope
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'source' => DncSource::FederalDnc->value,
            'reason' => null,
            'added_by' => 'test',
            'effective_date' => now()->subDay()->toDateString(),
        ];
    }

    public function forPhone(string $e164): self
    {
        return $this->state(fn () => [
            'phone' => $e164,
            'phone_hash' => hash('sha256', $e164),
        ]);
    }

    public function forTenant(string $tenantId): self
    {
        return $this->state(fn () => ['tenant_id' => $tenantId]);
    }

    public function source(DncSource $source): self
    {
        return $this->state(fn () => ['source' => $source->value]);
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'effective_date' => now()->subYear()->toDateString(),
            'expires_at' => now()->subDay()->toDateString(),
        ]);
    }
}
