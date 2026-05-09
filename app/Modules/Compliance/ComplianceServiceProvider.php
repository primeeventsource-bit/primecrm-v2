<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Compliance\Application\Console\ImportFederalDncCommand;
use Illuminate\Support\ServiceProvider;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // All services are concrete classes; container auto-resolves them.
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportFederalDncCommand::class,
            ]);
        }
    }
}
