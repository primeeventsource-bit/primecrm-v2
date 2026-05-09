<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

final class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $failureCode,
        public readonly ?string $failureReason,
    ) {}
}
