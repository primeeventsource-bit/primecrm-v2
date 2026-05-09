<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

use App\Modules\Commission\Domain\Models\CommissionCalculation;
use Illuminate\Foundation\Events\Dispatchable;

final class CommissionEarned
{
    use Dispatchable;

    public function __construct(public readonly CommissionCalculation $calculation) {}
}
