<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Shared\TenantContext;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id() ?? TenantFactory::new()->create()->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_'.$this->faker->lexify(str_repeat('?', 24)),
            'payment_method' => 'card',
            'amount' => $this->faker->numberBetween(100, 5000),
            'currency' => 'USD',
            'type' => Payment::TYPE_CHARGE,
            'status' => Payment::STATUS_PENDING,
        ];
    }

    public function cleared(): self
    {
        return $this->state(fn () => [
            'status' => Payment::STATUS_SUCCEEDED,
            'authorized_at' => now()->subMinutes(2),
            'captured_at' => now()->subMinute(),
            'cleared_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn () => [
            'status' => Payment::STATUS_FAILED,
            'failure_code' => 'card_declined',
            'failure_reason' => 'Your card was declined.',
        ]);
    }
}
