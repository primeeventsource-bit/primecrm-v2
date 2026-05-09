<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Strict-mode models in non-production: catch missing relations,
        // unfilled attributes, and discarded fillable in tests/CI.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
