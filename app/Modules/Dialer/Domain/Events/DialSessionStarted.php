<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Domain\Events;

use App\Modules\Dialer\Domain\Models\DialSession;
use Illuminate\Foundation\Events\Dispatchable;

final class DialSessionStarted
{
    use Dispatchable;

    public function __construct(public readonly DialSession $session) {}
}
