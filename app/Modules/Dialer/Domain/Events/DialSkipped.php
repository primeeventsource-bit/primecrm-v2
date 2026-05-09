<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Domain\Events;

use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when DialLeadJob refused to dial a lead — guardrail rejected, no
 * available agent in progressive mode, etc. Distinct from a failed call
 * (which actually went out and got a 4xx). Subscribers may push the lead
 * back into the campaign queue with a delay or remove it entirely.
 */
final class DialSkipped
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $reason,
        public readonly ?string $rejectionCode = null,
        public readonly ?string $sessionId = null,
    ) {}
}
