<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a call reaches a terminal status (completed, busy, no_answer,
 * failed, canceled). Carries the previous status so listeners can
 * distinguish "completed normally" from "abandoned by dialer".
 */
final class CallEnded
{
    use Dispatchable;

    public function __construct(
        public readonly Call $call,
        public readonly string $previousStatus,
    ) {}
}
