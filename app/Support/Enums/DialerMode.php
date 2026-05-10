<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum DialerMode: string
{
    case Manual = 'manual';
    case Preview = 'preview';
    case Progressive = 'progressive';
    case Predictive = 'predictive';
}
