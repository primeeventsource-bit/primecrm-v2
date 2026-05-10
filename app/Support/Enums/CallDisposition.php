<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CallDisposition: string
{
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case Callback = 'callback';
    case NoAnswer = 'no_answer';
    case Voicemail = 'voicemail';
    case Busy = 'busy';
    case BadNumber = 'bad_number';
    case DncRequest = 'dnc_request';
    case LanguageBarrier = 'language_barrier';
    case PitchPresented = 'pitch_presented';
    case SaleClosed = 'sale_closed';
    case TransferredToCloser = 'transferred_to_closer';
    case AbandonedByDialer = 'abandoned_by_dialer';

    public function requiresFollowUp(): bool
    {
        return in_array($this, [
            self::Callback, self::Voicemail, self::Busy, self::PitchPresented,
        ], true);
    }
}
