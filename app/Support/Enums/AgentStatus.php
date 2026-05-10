<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum AgentStatus: string
{
    case Available = 'available';
    case OnCall = 'on_call';
    case WrapUp = 'wrap_up';
    case OnBreak = 'on_break';
    case Offline = 'offline';

    public function isDialEligible(): bool
    {
        return $this === self::Available;
    }
}
