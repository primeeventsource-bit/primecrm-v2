<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Enums;

enum PartnerSiteListingStatus: string
{
    case Pending = 'pending';
    case Live = 'live';
    case Paused = 'paused';
    case Rejected = 'rejected';
    case Removed = 'removed';

    public function isHealthy(): bool
    {
        return $this === self::Live;
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Rejected, self::Paused], true);
    }
}
