<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Events;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Prime Connect video room has been ended (Twilio room-ended webhook
 * fired, or an agent/supervisor explicitly closed it).
 *
 * Subscribers: BroadcastDomainEvents → VideoRoomBroadcast (lobby
 * removes the row); ProcessCompletedRoomCompositionJob (S7 — kicks
 * off the recording composition pipeline once the room is terminal).
 */
final class RoomEnded
{
    use Dispatchable;

    public function __construct(public readonly Call $call) {}
}
