<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Deal;
use Illuminate\Foundation\Events\Dispatchable;

final class DealCreated
{
    use Dispatchable;

    public function __construct(public readonly Deal $deal) {}
}
