<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum LeadPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Hot = 'hot';

    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 5,
            self::High => 20,
            self::Hot => 100,
        };
    }
}
