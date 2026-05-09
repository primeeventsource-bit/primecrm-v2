<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

/**
 * Origin of a DNC entry. Determines retention and revocability:
 *   - federal_dnc / state_dnc are externally maintained — entries refresh
 *     on each delta import and aren't editable from the UI.
 *   - wireless_dnc tags numbers identified as mobile via NANPA OCN data.
 *   - internal_dnc covers numbers we've banned internally (problem callers,
 *     customers who told us "never call again" mid-call).
 *   - litigator_dnc are flagged numbers known to file TCPA suits — separate
 *     because the consequence of a single dial here is six figures.
 *   - customer_request is the standard "remove me from your list" path.
 */
enum DncSource: string
{
    case FederalDnc = 'federal_dnc';
    case StateDnc = 'state_dnc';
    case WirelessDnc = 'wireless_dnc';
    case InternalDnc = 'internal_dnc';
    case LitigatorDnc = 'litigator_dnc';
    case CustomerRequest = 'customer_request';

    /** Whether the source can be added/edited via the UI. */
    public function isUserEditable(): bool
    {
        return in_array($this, [
            self::InternalDnc,
            self::CustomerRequest,
            self::LitigatorDnc,
        ], true);
    }

    /** Federal/state lists are global and shared across tenants. */
    public function isGlobal(): bool
    {
        return in_array($this, [
            self::FederalDnc,
            self::StateDnc,
            self::WirelessDnc,
        ], true);
    }

    public function severity(): int
    {
        return match ($this) {
            self::LitigatorDnc => 100,
            self::FederalDnc, self::StateDnc => 90,
            self::WirelessDnc => 70,
            self::CustomerRequest => 80,
            self::InternalDnc => 60,
        };
    }
}
