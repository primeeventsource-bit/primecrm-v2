<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Events;

use App\Modules\Booking\Domain\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

final class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
