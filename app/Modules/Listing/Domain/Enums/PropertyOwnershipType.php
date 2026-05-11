<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Enums;

enum PropertyOwnershipType: string
{
    case FixedWeek = 'fixed_week';
    case FloatingWeek = 'floating_week';
    case Points = 'points';
    case Biennial = 'biennial';

    public function label(): string
    {
        return match ($this) {
            self::FixedWeek => 'Fixed week',
            self::FloatingWeek => 'Floating week',
            self::Points => 'Points',
            self::Biennial => 'Biennial',
        };
    }
}
