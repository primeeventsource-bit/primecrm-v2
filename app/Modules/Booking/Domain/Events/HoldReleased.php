<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Events;

use App\Modules\Booking\Domain\Models\InventoryHold;
use Illuminate\Foundation\Events\Dispatchable;

final class HoldReleased
{
    use Dispatchable;

    public function __construct(
        public readonly InventoryHold $hold,
        public readonly string $reason,
    ) {}
}
