<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Distribution;

use App\Modules\Listing\Domain\Enums\PartnerSiteListingStatus;

/**
 * Outcome of one partner-driver call.
 *
 * Drivers don't touch the database directly — they return one of these,
 * and ListingDistributor applies the changes. Keeps the driver layer
 * pure (easily testable, mockable) and the persistence layer
 * centralised (one place to add audit logging, retries, etc).
 *
 * On `ok = false`, `errorMessage` is shown to the user and the partner
 * row remains in its prior status. On `ok = true`, `nextStatus` is the
 * status to write; `viewCount`/`inquiryCount` are merged in if non-null.
 */
final readonly class PartnerDistributionResult
{
    public function __construct(
        public bool $ok,
        public ?PartnerSiteListingStatus $nextStatus = null,
        public ?string $externalListingId = null,
        public ?string $externalUrl = null,
        public ?string $rejectionReason = null,
        public ?int $viewCount = null,
        public ?int $inquiryCount = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(
        PartnerSiteListingStatus $status,
        ?string $externalListingId = null,
        ?string $externalUrl = null,
    ): self {
        return new self(
            ok: true,
            nextStatus: $status,
            externalListingId: $externalListingId,
            externalUrl: $externalUrl,
        );
    }

    public static function rejected(string $reason): self
    {
        return new self(
            ok: true,
            nextStatus: PartnerSiteListingStatus::Rejected,
            rejectionReason: $reason,
        );
    }

    public static function failure(string $message): self
    {
        return new self(ok: false, errorMessage: $message);
    }
}
