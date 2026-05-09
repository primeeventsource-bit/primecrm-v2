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

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Completed = 'completed';
}

enum InventoryStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
}

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
