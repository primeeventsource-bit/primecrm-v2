<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum InventoryStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
}
