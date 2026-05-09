<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Listeners;

use App\Modules\Commission\Application\Services\CommissionEngine;
use App\Modules\Commission\Application\Services\CommissionEventLog;
use App\Modules\Payment\Domain\Events\ChargebackOccurred;

/**
 * Chargeback → commission reversal.
 *
 * Same flow as refund, but tracked as a separate commission event type
 * so reporting can distinguish chargeback rate (an agent KPI) from
 * voluntary refund rate.
 */
final class OnChargebackOccurredListener
{
    public function __construct(
        private readonly CommissionEventLog $log,
        private readonly CommissionEngine $engine,
    ) {}

    public function handle(ChargebackOccurred $event): void
    {
        $chargeback = $event->chargeback;
        $original = $event->originalCharge;

        $commissionEvent = $this->log->append(
            eventType: 'payment.chargeback',
            sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
            sourceEntityId: $chargeback->id,
            payload: [
                'chargeback_payment_id' => $chargeback->id,
                'original_payment_id' => $original->id,
                'amount' => (float) $chargeback->amount,
                'currency' => $chargeback->currency,
            ],
            idempotencyKey: "payment.chargeback:{$chargeback->id}",
            occurredAt: $chargeback->cleared_at,
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
