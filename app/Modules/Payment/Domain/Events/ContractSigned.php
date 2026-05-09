<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Payment\Domain\Models\Contract;
use Illuminate\Foundation\Events\Dispatchable;

final class ContractSigned
{
    use Dispatchable;

    public function __construct(public readonly Contract $contract) {}
}
