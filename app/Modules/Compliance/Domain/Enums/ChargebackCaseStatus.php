<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

enum ChargebackCaseStatus: string
{
    case Received = 'received';
    case EvidenceGathering = 'evidence_gathering';
    case EvidenceSubmitted = 'evidence_submitted';
    case Won = 'won';
    case Lost = 'lost';

    public function isOpen(): bool
    {
        return in_array($this, [
            self::Received, self::EvidenceGathering, self::EvidenceSubmitted,
        ], true);
    }
}
