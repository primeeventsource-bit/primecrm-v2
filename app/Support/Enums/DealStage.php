<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum DealStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case PitchPresented = 'pitch_presented';
    case Negotiating = 'negotiating';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function isTerminal(): bool
    {
        return $this === self::ClosedWon || $this === self::ClosedLost;
    }

    public function isWon(): bool
    {
        return $this === self::ClosedWon;
    }
}
