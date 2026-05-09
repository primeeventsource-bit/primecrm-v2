<?php

declare(strict_types=1);

namespace App\Modules\Payment;

use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;
use App\Modules\Payment\Infrastructure\Gateway\StripeGateway;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            return new StripeClient((string) config('services.stripe.secret', env('STRIPE_SECRET', '')));
        });

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return new StripeGateway(
                $app->make(StripeClient::class),
                webhookSecret: (string) config('services.stripe.webhook_secret', env('STRIPE_WEBHOOK_SECRET', '')),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
