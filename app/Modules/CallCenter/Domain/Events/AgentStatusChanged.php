<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Support\Enums\AgentStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every agent presence transition. Drives the supervisor war
 * room real-time tiles and the predictive dialer's "agents available"
 * count (though the dialer reads Redis, not events — events are for the
 * UI / analytics).
 */
final class AgentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $agentId,
        public readonly AgentStatus $from,
        public readonly AgentStatus $to,
        public readonly ?string $callId = null,
    ) {}
}
