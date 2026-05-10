<?php

declare(strict_types=1);

namespace App\Modules\Listing\Domain\Enums;

enum PropertySeason: string
{
    case Platinum = 'platinum';
    case Gold = 'gold';
    case Silver = 'silver';
    case Bronze = 'bronze';
    case Red = 'red';
    case White = 'white';
    case Blue = 'blue';
    case None = 'none';
}
