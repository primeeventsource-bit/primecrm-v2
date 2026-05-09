<?php

declare(strict_types=1);

namespace App\Modules\Customer\Domain\Events;

use App\Modules\Customer\Domain\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

final class CustomerCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Customer $customer,
        public readonly string $source, // 'lead_conversion', 'manual', 'import'
    ) {}
}
