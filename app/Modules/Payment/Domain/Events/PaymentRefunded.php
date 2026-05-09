<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a refund payment is created and succeeds.
 *
 * The CommissionEngine subscribes and writes a reversal commission_event
 * keyed off the original charge. The reversal produces negative
 * commission_calculations that net against the agent's prior earnings —
 * preserving the audit trail rather than mutating the originals.
 */
final class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $refund,
        public readonly Payment $originalCharge,
    ) {}
}
