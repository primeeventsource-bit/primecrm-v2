<?php

declare(strict_types=1);

namespace App\Modules\Lead;

use App\Modules\Lead\Application\Listeners\AutoAssignNewLead;
use App\Modules\Lead\Domain\Events\LeadCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class LeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Services are picked up by autoloader and resolved by their type-hints
        // through the container; no explicit bindings required here yet.
    }

    public function boot(): void
    {
        // Async-by-default: queued listener so HTTP requests aren't blocked
        // by the routing engine's database round-trips.
        Event::listen(LeadCreated::class, [AutoAssignNewLead::class, 'handle']);
    }
}
