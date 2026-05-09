<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

/**
 * Why the compliance guardrail blocked a dial.
 *
 * Stable wire format — used in audit logs, lead.substatus, and the dialer
 * UI. Adding cases is fine; renaming or removing breaks downstream filters.
 */
enum GuardrailRejectionCode: string
{
    case DncFederal = 'dnc_federal';
    case DncState = 'dnc_state';
    case DncWireless = 'dnc_wireless';
    case DncLitigator = 'dnc_litigator';
    case DncInternal = 'dnc_internal';
    case DncCustomerRequest = 'dnc_customer_request';

    case ConsentMissing = 'consent_missing';
    case ConsentRevoked = 'consent_revoked';
    case ConsentExpired = 'consent_expired';

    case FrequencyTooSoon = 'frequency_too_soon';
    case FrequencyDailyCap = 'frequency_daily_cap';
    case FrequencyMonthlyCap = 'frequency_monthly_cap';

    case OutsideCallingWindow = 'outside_calling_window';
    case BlockedWeekday = 'blocked_weekday';
    case BlockedHoliday = 'blocked_holiday';

    case LeadStatusTerminal = 'lead_status_terminal';
    case LeadOnDncFlag = 'lead_on_dnc_flag';
    case BadNumber = 'bad_number';

    public function category(): string
    {
        return match (true) {
            str_starts_with($this->value, 'dnc_') => 'dnc',
            str_starts_with($this->value, 'consent_') => 'consent',
            str_starts_with($this->value, 'frequency_') => 'frequency',
            in_array($this, [self::OutsideCallingWindow, self::BlockedWeekday, self::BlockedHoliday], true) => 'window',
            default => 'lead_state',
        };
    }
}
