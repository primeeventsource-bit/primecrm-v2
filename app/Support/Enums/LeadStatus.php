<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case PitchPresented = 'pitch_presented';
    case Negotiating = 'negotiating';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';
    case Dnc = 'dnc';
    case DoNotContact = 'do_not_contact';
    case BadNumber = 'bad_number';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::ClosedWon, self::ClosedLost, self::Dnc,
            self::DoNotContact, self::BadNumber,
        ], true);
    }

    public function isContactable(): bool
    {
        return ! $this->isTerminal();
    }
}
