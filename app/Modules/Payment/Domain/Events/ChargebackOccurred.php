<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when Stripe reports a chargeback (charge.dispute.created with
 * a final lost outcome, or webhook-confirmed dispute).
 *
 * Same downstream effect as a refund — commission reversal — but tracked
 * separately for ops reporting (chargeback rate by agent is a real KPI).
 */
final class ChargebackOccurred
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $chargeback,
        public readonly Payment $originalCharge,
    ) {}
}
