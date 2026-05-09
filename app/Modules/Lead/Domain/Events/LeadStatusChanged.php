<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Events;

use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadStatus;
use Illuminate\Foundation\Events\Dispatchable;

final class LeadStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly LeadStatus $from,
        public readonly LeadStatus $to,
        public readonly ?string $changedByUserId,
    ) {}
}
