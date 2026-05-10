<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Lifecycle of a Twilio Video Room (only set when calls.medium = video).
 *
 * Distinct from CallStatus, which models a voice call's leg (queued,
 * ringing, in_progress, completed, etc). A video room can be Created
 * before any participant joins (scheduled rooms), transitions to
 * InProgress on the first participant-connected webhook, and to
 * Completed when Twilio fires room-ended.
 */
enum RoomStatus: string
{
    case Created = 'created';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function isActive(): bool
    {
        return $this === self::InProgress;
    }
}
