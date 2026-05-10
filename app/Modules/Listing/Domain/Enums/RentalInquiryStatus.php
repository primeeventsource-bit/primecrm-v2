<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Enums;

enum RentalInquiryStatus: string
{
    case New = 'new';
    case Responded = 'responded';
    case Negotiating = 'negotiating';
    case Booked = 'booked';
    case Lost = 'lost';

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Responded, self::Negotiating], true);
    }

    public function isWon(): bool
    {
        return $this === self::Booked;
    }
}
