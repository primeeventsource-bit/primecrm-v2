<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CallDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
    case InternalTransfer = 'internal_transfer';
}
