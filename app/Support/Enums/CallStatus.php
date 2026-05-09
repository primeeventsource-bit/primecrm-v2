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

enum CallDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
    case InternalTransfer = 'internal_transfer';
}

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

enum DialerMode: string
{
    case Manual = 'manual';
    case Preview = 'preview';
    case Progressive = 'progressive';
    case Predictive = 'predictive';
}

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
