<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Events;

use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the compliance guardrail rejects a lead.
 *
 * Rejection reasons land in the audit log and on the lead's substatus.
 * The dialer subscribes to remove the lead from active dial queues.
 */
final class LeadRejectedByCompliance
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $rejectionCode,
        public readonly string $reason,
    ) {}
}
