<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Listeners;

use App\Modules\Commission\Application\Services\CommissionEngine;
use App\Modules\Commission\Application\Services\CommissionEventLog;
use App\Modules\Sales\Domain\Events\DealClosedWon;

/**
 * Deal closed → optional commission event.
 *
 * Some plans pay on close-won, some only on payment-cleared. The engine
 * applies whichever plan rules match `deal.closed_won` as the trigger.
 * Status defaults to `pending` for these (the engine handles that),
 * letting tenants surface "earnings on the books vs payable" in the
 * agent dashboard.
 */
final class OnDealClosedWonListener
{
    public function __construct(
        private readonly CommissionEventLog $log,
        private readonly CommissionEngine $engine,
    ) {}

    public function handle(DealClosedWon $event): void
    {
        $deal = $event->deal;

        $commissionEvent = $this->log->append(
            eventType: 'deal.closed_won',
            sourceEntityType: \App\Modules\Sales\Domain\Models\Deal::class,
            sourceEntityId: $deal->id,
            payload: [
                'deal_id' => $deal->id,
                'lead_id' => $deal->lead_id,
                'agent_id' => $deal->agent_id,
                'fronter_id' => $deal->fronter_id,
                'total_value' => (float) $deal->total_value,
                'payable_amount' => (float) $deal->payable_amount,
                'currency' => $deal->currency,
            ],
            idempotencyKey: "deal.closed_won:{$deal->id}",
            occurredAt: $deal->closed_at,
        );

        if ($commissionEvent === null) {
            return;
        }

        $this->engine->process($commissionEvent);
    }
}
