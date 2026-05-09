<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Listeners;

use App\Modules\Commission\Application\Services\CommissionEngine;
use App\Modules\Commission\Application\Services\CommissionEventLog;
use App\Modules\Payment\Domain\Events\PaymentRefunded;

/**
 * Refund → commission reversal.
 *
 * Writes a `payment.refunded` commission event, then asks the engine
 * to undo all calculations tied to the original `payment.cleared`
 * event for the same charge.
 */
final class OnPaymentRefundedListener
{
    public function __construct(
        private readonly CommissionEventLog $log,
        private readonly CommissionEngine $engine,
    ) {}

    public function handle(PaymentRefunded $event): void
    {
        $refund = $event->refund;
        $original = $event->originalCharge;

        $commissionEvent = $this->log->append(
            eventType: 'payment.refunded',
            sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
            sourceEntityId: $refund->id,
            payload: [
                'refund_payment_id' => $refund->id,
                'original_payment_id' => $original->id,
                'amount' => (float) $refund->amount,
                'currency' => $refund->currency,
            ],
            idempotencyKey: "payment.refunded:{$refund->id}",
            occurredAt: $refund->refunded_at,
        );

        if ($commissionEvent === null) {
            return;
        }

        $this->engine->reverseFromEvent(
            $commissionEvent,
            originalEventIdempotencyKey: "payment.cleared:{$original->id}",
        );
    }
}
