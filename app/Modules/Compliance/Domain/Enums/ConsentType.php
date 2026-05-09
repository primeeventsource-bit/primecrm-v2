<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

/**
 * TCPA consent categories.
 *
 *   marketing      — generic permission to contact for sales pitches
 *   transactional  — booking confirmations, payment receipts; mostly exempt
 *                    from express-consent rules but we record anyway
 *   autodialer     — REQUIRED for predictive dialer use to a wireless number.
 *                    "Express written consent" per FCC rules.
 *   sms            — text message permission. Separate from voice consent.
 */
enum ConsentType: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';
    case Autodialer = 'autodialer';
    case Sms = 'sms';

    /**
     * The consent level the predictive dialer requires before dialing
     * a wireless number with an automated system.
     */
    public function isRequiredForAutodial(): bool
    {
        return $this === self::Autodialer;
    }
}
