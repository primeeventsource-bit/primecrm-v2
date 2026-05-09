<?php

declare(strict_types=1);

namespace App\Modules\Commission;

use App\Modules\Commission\Application\Listeners\OnChargebackOccurredListener;
use App\Modules\Commission\Application\Listeners\OnDealClosedWonListener;
use App\Modules\Commission\Application\Listeners\OnPaymentClearedListener;
use App\Modules\Commission\Application\Listeners\OnPaymentRefundedListener;
use App\Modules\Payment\Domain\Events\ChargebackOccurred;
use App\Modules\Payment\Domain\Events\PaymentCleared;
use App\Modules\Payment\Domain\Events\PaymentRefunded;
use App\Modules\Sales\Domain\Events\DealClosedWon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CommissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(PaymentCleared::class, [OnPaymentClearedListener::class, 'handle']);
        Event::listen(PaymentRefunded::class, [OnPaymentRefundedListener::class, 'handle']);
        Event::listen(ChargebackOccurred::class, [OnChargebackOccurredListener::class, 'handle']);
        Event::listen(DealClosedWon::class, [OnDealClosedWonListener::class, 'handle']);
    }
}
