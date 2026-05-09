<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Deal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a deal transitions to ClosedWon. The Commission module
 * subscribes to write a `deal.closed_won` commission event — that
 * event is the one ingredient the rule engine needs to compute the
 * agent's commission immediately, even before payment clears.
 *
 * The rule may produce a `pending` calculation (waiting for payment
 * to clear) or a `payable` one (commission paid on close, not on
 * payment). Tenants configure that via plan rules.
 */
final class DealClosedWon
{
    use Dispatchable;

    public function __construct(public readonly Deal $deal) {}
}
