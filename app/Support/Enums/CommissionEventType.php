<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CommissionEventType: string
{
    case DealClosedWon = 'deal.closed_won';
    case DealClosedLost = 'deal.closed_lost';
    case BookingConfirmed = 'booking.confirmed';
    case BookingCancelled = 'booking.cancelled';
    case PaymentCleared = 'payment.cleared';
    case PaymentRefunded = 'payment.refunded';
    case PaymentChargedBack = 'payment.chargedback';
    case ContractSigned = 'contract.signed';
    case ManualAdjustment = 'manual.adjustment';

    public function isReversal(): bool
    {
        return in_array($this, [
            self::DealClosedLost, self::BookingCancelled,
            self::PaymentRefunded, self::PaymentChargedBack,
        ], true);
    }
}
