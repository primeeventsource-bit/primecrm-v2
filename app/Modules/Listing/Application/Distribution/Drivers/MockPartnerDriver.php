<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Distribution\Drivers;

use App\Modules\Listing\Application\Distribution\PartnerDistributionResult;
use App\Modules\Listing\Application\Distribution\PartnerDriver;
use App\Modules\Listing\Domain\Enums\PartnerSiteListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use Illuminate\Support\Str;

/**
 * Default driver — simulates a partner-site integration without
 * making any external API call. Realistic-enough behaviour to drive
 * the demo end-to-end:
 *
 *   push   80% goes live immediately, 15% lands as 'pending' (will
 *          flip to 'live' on the next sync), 5% rejected with a
 *          plausible reason (photos / pricing / terms).
 *   pause  always succeeds.
 *   resume always succeeds.
 *   sync   bumps view/inquiry counters by realistic small numbers
 *          AND flips any 'pending' rows to 'live' so the lifecycle
 *          actually progresses.
 *
 * Real integrations replace the random rolls with HTTP calls; the
 * result-shape contract stays identical.
 */
final class MockPartnerDriver implements PartnerDriver
{
    public function push(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        $roll = random_int(1, 100);

        if ($roll <= 5) {
            $reasons = [
                'Photos do not meet minimum resolution requirements',
                'Listing description references prohibited language',
                'Asking price exceeds platform cap for this resort',
                'Property requires verified-host status before listing',
            ];

            return PartnerDistributionResult::rejected($reasons[array_rand($reasons)]);
        }

        $external = $site->slug.'-'.Str::lower(Str::random(12));
        $url = sprintf(
            'https://%s.example/listings/%s',
            $site->slug,
            Str::lower(Str::random(8))
        );

        if ($roll <= 20) {
            // 'pending' = the partner accepted the push but hasn't
            // surfaced it on their search index yet. Real integrations
            // call this "under review".
            return new PartnerDistributionResult(
                ok: true,
                nextStatus: PartnerSiteListingStatus::Pending,
                externalListingId: $external,
                externalUrl: $url,
            );
        }

        return PartnerDistributionResult::success(
            status: PartnerSiteListingStatus::Live,
            externalListingId: $external,
            externalUrl: $url,
        );
    }

    public function pause(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        return PartnerDistributionResult::success(PartnerSiteListingStatus::Paused);
    }

    public function resume(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        return PartnerDistributionResult::success(PartnerSiteListingStatus::Live);
    }

    public function sync(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        // Pending rows flip to live on first sync (real partners
        // typically resolve "under review" inside an hour).
        $next = $row->status === PartnerSiteListingStatus::Pending
            ? PartnerSiteListingStatus::Live
            : $row->status;

        // Counter drift — small bumps. Live rows accumulate views
        // faster than inquiries.
        $viewBump = $next === PartnerSiteListingStatus::Live ? random_int(2, 18) : 0;
        $inquiryBump = $next === PartnerSiteListingStatus::Live && random_int(1, 100) <= 12
            ? 1
            : 0;

        return new PartnerDistributionResult(
            ok: true,
            nextStatus: $next,
            externalListingId: $row->external_listing_id,
            externalUrl: $row->external_url,
            viewCount: (int) $row->view_count + $viewBump,
            inquiryCount: (int) $row->inquiry_count + $inquiryBump,
        );
    }
}
