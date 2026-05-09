<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            // tenant_id is normally injected by the TenantScoped::creating hook
            // when there's a resolved context; tests that span tenants pass it
            // explicitly.
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => UserRole::Agent->value,
            'phone' => $this->faker->e164PhoneNumber(),
            'extension' => (string) $this->faker->numberBetween(1000, 9999),
            'timezone' => 'America/New_York',
            'skills' => [],
            'is_panama_based' => false,
        ];
    }

    public function admin(): self
    {
        return $this->state(fn () => ['role' => UserRole::Admin->value]);
    }

    public function supervisor(): self
    {
        return $this->state(fn () => ['role' => UserRole::Supervisor->value]);
    }

    public function agent(): self
    {
        return $this->state(fn () => ['role' => UserRole::Agent->value]);
    }

    public function closer(): self
    {
        return $this->state(fn () => ['role' => UserRole::Closer->value]);
    }
}
