<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObjects;

use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;

/**
 * Result of a compliance gate check.
 *
 * Two shapes:
 *   - allow():  the dialer may proceed. metadata is optional (debug context).
 *   - reject(): the dialer must abort. carries machine-readable code, a
 *     human-readable reason, and metadata that ends up in the audit log.
 *
 * The dialer (Response 3) treats this as the single source of truth.
 * It does NOT re-derive compliance state from anywhere else.
 */
final class GuardrailDecision
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public readonly bool $allowed,
        public readonly ?GuardrailRejectionCode $rejectionCode = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function allow(array $metadata = []): self
    {
        return new self(allowed: true, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function reject(GuardrailRejectionCode $code, string $reason, array $metadata = []): self
    {
        return new self(
            allowed: false,
            rejectionCode: $code,
            reason: $reason,
            metadata: $metadata,
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isRejected(): bool
    {
        return ! $this->allowed;
    }

    public function category(): ?string
    {
        return $this->rejectionCode?->category();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'rejection_code' => $this->rejectionCode?->value,
            'category' => $this->category(),
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
