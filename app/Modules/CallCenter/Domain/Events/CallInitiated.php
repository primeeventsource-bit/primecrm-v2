<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the dialer has handed a call to the provider — Twilio has
 * accepted our `calls.create()` and returned a CallSid. The number is
 * now ringing or about to ring.
 *
 * Subscribers: the agent UI (push status), the contact_attempts logger
 * (already updated via RecordContactAttemptAction at this point).
 */
final class CallInitiated
{
    use Dispatchable;

    public function __construct(public readonly Call $call) {}
}
