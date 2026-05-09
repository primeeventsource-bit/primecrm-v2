<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a payment moves to status=succeeded AND cleared_at is set.
 * The Commission module's listener subscribes to this and writes a
 * `payment.cleared` commission event — that's what triggers the
 * agent's commission to be marked payable.
 *
 * For deposits, the partial amount cleared is the base for proportional
 * commission. The CommissionEngine handles the math.
 */
final class PaymentCleared
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
