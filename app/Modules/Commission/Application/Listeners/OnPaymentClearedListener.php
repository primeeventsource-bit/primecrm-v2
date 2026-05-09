<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Listeners;

use App\Modules\Commission\Application\Services\CommissionEngine;
use App\Modules\Commission\Application\Services\CommissionEventLog;
use App\Modules\Payment\Domain\Events\PaymentCleared;

/**
 * Translates PaymentCleared → commission_event(payment.cleared) and
 * runs it through the CommissionEngine to produce calculations.
 *
 * The idempotency_key is `payment.cleared:{payment_id}`. If Stripe sends
 * the webhook twice (it does; retry policy), both flows reach this
 * listener — the SECOND attempt to write the event row collides on the
 * unique key, the log returns null, and the engine never runs again.
 */
final class OnPaymentClearedListener
{
    public function __construct(
        private readonly CommissionEventLog $log,
        private readonly CommissionEngine $engine,
    ) {}

    public function handle(PaymentCleared $event): void
    {
        $payment = $event->payment;

        $commissionEvent = $this->log->append(
            eventType: 'payment.cleared',
            sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
            sourceEntityId: $payment->id,
            payload: [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'deal_id' => $payment->deal_id,
                'lead_id' => $payment->lead_id,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'cleared_at' => $payment->cleared_at?->toIso8601String(),
            ],
            idempotencyKey: "payment.cleared:{$payment->id}",
            occurredAt: $payment->cleared_at,
        );

        if ($commissionEvent === null) {
            return; // duplicate, already processed
        }

        $this->engine->process($commissionEvent);
    }
}
