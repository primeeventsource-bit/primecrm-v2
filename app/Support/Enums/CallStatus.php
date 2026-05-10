<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CallStatus: string
{
    case Queued = 'queued';
    case Initiated = 'initiated';
    case Ringing = 'ringing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed, self::Busy, self::NoAnswer,
            self::Failed, self::Canceled,
        ], true);
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Ringing, self::InProgress], true);
    }

    public function countsAsConnected(): bool
    {
        return $this === self::Completed || $this === self::InProgress;
    }
}
