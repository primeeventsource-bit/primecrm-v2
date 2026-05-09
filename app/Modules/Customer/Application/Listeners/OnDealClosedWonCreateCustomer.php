<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application\Listeners;

use App\Modules\Customer\Application\Actions\CreateCustomerFromLead;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Events\DealClosedWon;

/**
 * On every deal.closed_won, ensure a Customer record exists for the
 * lead. Idempotent — re-firing the event for the same deal won't
 * double-create. (CreateCustomerFromLead handles the
 * already-exists case by bumping metrics rather than creating.)
 */
final class OnDealClosedWonCreateCustomer
{
    public function __construct(private readonly CreateCustomerFromLead $action) {}

    public function handle(DealClosedWon $event): void
    {
        $deal = $event->deal;
        $lead = Lead::query()->find($deal->lead_id);

        if ($lead === null) {
            // Defensive: a deal without a lead shouldn't happen with our
            // schema (lead_id is NOT NULL on deals), but log + skip rather
            // than crash if it does.
            logger()->warning('DealClosedWon fired for deal with no lead', [
                'deal_id' => $deal->id,
                'lead_id' => $deal->lead_id,
            ]);

            return;
        }

        $this->action->execute($lead, $deal);
    }
}
