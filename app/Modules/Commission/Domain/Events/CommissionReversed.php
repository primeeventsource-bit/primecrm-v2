<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Commission\Domain\Models\CommissionCalculation;
use Illuminate\Foundation\Events\Dispatchable;

final class CommissionReversed
{
    use Dispatchable;

    public function __construct(
        public readonly CommissionCalculation $reversal,
        public readonly CommissionCalculation $original,
    ) {}
}
