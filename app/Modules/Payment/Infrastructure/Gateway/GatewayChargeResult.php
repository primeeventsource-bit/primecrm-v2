<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Gateway;

final class GatewayChargeResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $providerChargeId,
        public readonly string $status,           // pending, processing, succeeded, requires_action, failed
        public readonly ?string $cardLastFour = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $raw = [],
    ) {}
}
