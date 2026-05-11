<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

enum RefundCaseStatus: string
{
    case Opened = 'opened';
    case Investigating = 'investigating';
    case Approved = 'approved';
    case Denied = 'denied';
    case Processed = 'processed';
    case EscalatedToChargeback = 'escalated_to_chargeback';

    public function isOpen(): bool
    {
        return in_array($this, [self::Opened, self::Investigating, self::Approved], true);
    }

    public function isResolved(): bool
    {
        return in_array($this, [self::Denied, self::Processed, self::EscalatedToChargeback], true);
    }
}
