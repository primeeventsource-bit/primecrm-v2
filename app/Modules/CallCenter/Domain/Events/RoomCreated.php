<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Prime Connect video room has been created (instant or scheduled).
 *
 * For instant rooms, the Twilio room SID is already populated and the
 * room is ready to be joined. For scheduled rooms, the SID is null
 * until the first participant joins (we lazy-create on the Twilio side
 * so we don't pay for empty rooms sitting around).
 *
 * Subscribers: BroadcastDomainEvents → VideoRoomBroadcast (lobby live
 * update); audit log; future analytics roll-ups.
 */
final class RoomCreated
{
    use Dispatchable;

    public function __construct(public readonly Call $call) {}
}
