<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Exceptions;

/**
 * Raised when a hold or booking attempt loses the race for the unique
 * (inventory_unit_id, check_in_date) constraint — i.e. another agent
 * grabbed the unit between our SELECT and our INSERT/UPDATE.
 *
 * The HoldService catches the underlying Postgres unique-violation and
 * raises this so callers get a domain-language exception, not a database
 * error.
 */
final class InventoryUnavailableException extends \DomainException
{
    public static function forUnit(string $unitId, string $checkInDate): self
    {
        return new self(
            "Unit {$unitId} on {$checkInDate} is no longer available."
        );
    }
}
