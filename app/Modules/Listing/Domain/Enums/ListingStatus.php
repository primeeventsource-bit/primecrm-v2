<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Enums;

/**
 * Lifecycle of a listing from creation to terminal outcome.
 *
 * Sales pipeline stops at Live; everything past it is a fulfillment-side
 * state machine driven by partner-site activity and renter behaviour.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case PendingDistribution = 'pending_distribution';
    case Live = 'live';
    case InquiryReceived = 'inquiry_received';
    case PendingBooking = 'pending_booking';
    case Booked = 'booked';
    case RentedCompleted = 'rented_completed';
    case UnrentedExpired = 'unrented_expired';
    case Cancelled = 'cancelled';

    public function isLive(): bool
    {
        return in_array($this, [
            self::Live, self::InquiryReceived, self::PendingBooking,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::RentedCompleted, self::UnrentedExpired, self::Cancelled,
        ], true);
    }

    public function isSuccess(): bool
    {
        return $this === self::RentedCompleted;
    }
}
