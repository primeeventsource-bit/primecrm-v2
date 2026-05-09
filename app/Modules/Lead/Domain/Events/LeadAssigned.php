<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Events;

use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a lead is assigned (or reassigned) to an agent.
 *
 * The dialer (Response 3) listens to push the lead onto the agent's
 * preload queue via WebSocket so the next click-to-call is instant.
 */
final class LeadAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $agentId,
        public readonly ?string $previousAgentId,
        public readonly string $reason,
    ) {}
}
