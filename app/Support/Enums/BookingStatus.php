<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Completed = 'completed';
}
