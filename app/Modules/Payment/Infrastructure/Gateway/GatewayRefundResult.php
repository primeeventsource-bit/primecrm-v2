<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Gateway;

final class GatewayRefundResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $providerRefundId,
        public readonly float $amount,
        public readonly string $status,
        public readonly array $raw = [],
    ) {}
}
