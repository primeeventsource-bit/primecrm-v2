<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Chargeback = 'chargeback';

    public function isCleared(): bool
    {
        return $this === self::Succeeded;
    }

    public function isReversed(): bool
    {
        return in_array($this, [
            self::Refunded, self::PartiallyRefunded, self::Chargeback,
        ], true);
    }
}
