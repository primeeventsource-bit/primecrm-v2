<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Shared\Broadcasting\BroadcastDomainEvents;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel only auto-binds Faker\Generator outside production
        // (Illuminate\Database\DatabaseServiceProvider). We need it in
        // production too so DemoSeeder + factories resolve cleanly when
        // run via `cloud cmd:run main --cmd="db:seed --class=DemoSeeder"`.
        $this->app->singleton(FakerGenerator::class, function (): FakerGenerator {
            return FakerFactory::create(config('app.faker_locale', 'en_US'));
        });
    }

    public function boot(): void
    {
        // Strict-mode models in non-production: catch missing relations,
        // unfilled attributes, and discarded fillable in tests/CI.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Bridge domain events → broadcasting layer (single subscription point).
        Event::subscribe(BroadcastDomainEvents::class);
    }
}
