<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Commission\Domain\Models\CommissionPayout;
use Illuminate\Foundation\Events\Dispatchable;

final class PayoutApproved
{
    use Dispatchable;

    public function __construct(public readonly CommissionPayout $payout) {}
}
