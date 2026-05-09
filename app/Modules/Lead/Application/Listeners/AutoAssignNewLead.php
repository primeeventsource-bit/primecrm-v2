<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Listeners;

use App\Modules\Lead\Application\Jobs\AssignLeadJob;
use App\Modules\Lead\Domain\Events\LeadCreated;

/**
 * Reacts to LeadCreated by queueing assignment if the lead doesn't already
 * have an agent. Manual creation (where the operator picks the agent at
 * creation time) bypasses this naturally — the lead arrives pre-assigned.
 *
 * Dispatched async (queued listener) so HTTP request lifecycle isn't blocked.
 */
final class AutoAssignNewLead
{
    public function handle(LeadCreated $event): void
    {
        if ($event->lead->assigned_agent_id !== null) {
            return;
        }

        if (! $event->lead->isContactable()) {
            return;
        }

        AssignLeadJob::dispatch($event->lead->id);
    }
}
