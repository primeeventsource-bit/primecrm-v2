<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Enums\DealStage;
use Illuminate\Foundation\Events\Dispatchable;

final class DealStageChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Deal $deal,
        public readonly DealStage $from,
        public readonly DealStage $to,
        public readonly ?string $changedByUserId,
        public readonly ?string $reason = null,
    ) {}
}
