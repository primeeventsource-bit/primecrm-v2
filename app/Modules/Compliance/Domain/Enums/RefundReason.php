<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

enum RefundReason: string
{
    case NoRenterFound = 'no_renter_found';
    case ServiceNotDelivered = 'service_not_delivered';
    case MisrepresentationClaim = 'misrepresentation_claim';
    case OwnerChangedMind = 'owner_changed_mind';
    case DuplicateCharge = 'duplicate_charge';
    case Unauthorized = 'unauthorized';
    case Other = 'other';

    public function isHighRisk(): bool
    {
        // These reasons trail higher rates of escalation to chargeback
        // or AG complaint. Open cases under these reasons should
        // surface to supervisors immediately.
        return in_array($this, [
            self::MisrepresentationClaim,
            self::Unauthorized,
            self::ServiceNotDelivered,
        ], true);
    }
}
