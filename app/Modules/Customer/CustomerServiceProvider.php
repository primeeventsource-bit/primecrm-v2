<?php

declare(strict_types=1);

namespace App\Modules\Customer;

use App\Modules\Customer\Application\Listeners\OnDealClosedWonCreateCustomer;
use App\Modules\Sales\Domain\Events\DealClosedWon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(DealClosedWon::class, [OnDealClosedWonCreateCustomer::class, 'handle']);
    }
}
