<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Events;

use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired exactly once when a Lead enters the system, regardless of source
 * (manual entry, API push, CSV import). Subscribers in subsequent responses:
 *
 *   - LeadScoringListener (queues ScoreLeadJob)
 *   - LeadAssignmentListener (queues AssignLeadJob if unassigned)
 *   - ComplianceListener (logs initial DNC/consent state)
 */
final class LeadCreated
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
