<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the called party answers AND an agent is on the line.
 *
 * In predictive mode, "answered_at" can fire before the agent is
 * connected (the dialer "abandons" if no agent is available within
 * the FCC's 2-second budget). We only emit CallConnected when a
 * human is talking to a human.
 */
final class CallConnected
{
    use Dispatchable;

    public function __construct(public readonly Call $call) {}
}
