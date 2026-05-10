<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use App\Modules\Listing\Application\Distribution\Drivers\MockPartnerDriver;
use App\Modules\Listing\Application\Distribution\Drivers\RedweekDriver;
use App\Modules\Listing\Application\Distribution\PartnerDistributionResult;
use App\Modules\Listing\Application\Distribution\PartnerDriver;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use Illuminate\Container\Container;

/**
 * Orchestrates partner-site distribution actions.
 *
 *   - Resolves the right driver for a partner site (slug → class map).
 *   - Persists the driver's result back to the partner_site_listings
 *     row in one place — drivers stay pure.
 *   - Records timestamps that drive the dashboard's "time-to-live"
 *     metric: pushed_at (when we initiated the push) and went_live_at
 *     (when the partner confirmed it surfaced).
 *
 * Adding a real integration:
 *   1. Implement PartnerDriver in a new class under Drivers/.
 *   2. Add it to the slug map below.
 *   3. The driver will be auto-resolved for any partner_site whose slug matches.
 */
final class ListingDistributor
{
    /**
     * slug → driver-class map. Anything not in the map falls back to
     * MockPartnerDriver so demo/empty configs still work end-to-end.
     *
     * @var array<string, class-string<PartnerDriver>>
     */
    private const DRIVER_MAP = [
        'redweek' => RedweekDriver::class,
        // 'airbnb' => AirbnbDriver::class,        // future
        // 'vrbo' => VrboDriver::class,            // future
        // 'smtn' => SellMyTimeshareNowDriver::class,  // future
    ];

    public function __construct(private readonly Container $container) {}

    public function push(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        // Stamp pushed_at NOW so time-to-live can compute accurately
        // even if the partner takes time to surface the listing.
        $row->forceFill(['pushed_at' => now()])->save();

        $result = $this->driver($site)->push($listing, $site, $row);

        $this->persist($row, $result, markWentLiveOnSuccess: true);

        return $result;
    }

    public function pause(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        $result = $this->driver($site)->pause($listing, $site, $row);
        $this->persist($row, $result);

        return $result;
    }

    public function resume(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        $result = $this->driver($site)->resume($listing, $site, $row);
        $this->persist($row, $result, markWentLiveOnSuccess: true);

        return $result;
    }

    public function sync(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        $result = $this->driver($site)->sync($listing, $site, $row);
        $this->persist($row, $result, markWentLiveOnSuccess: true);

        $row->forceFill(['last_synced_at' => now()])->save();

        return $result;
    }

    private function driver(PartnerSite $site): PartnerDriver
    {
        $class = self::DRIVER_MAP[$site->slug] ?? MockPartnerDriver::class;

        return $this->container->make($class);
    }

    /**
     * Apply a driver result to the partner_site_listing row.
     *
     * @param  bool  $markWentLiveOnSuccess  Set went_live_at to now()
     *      when the row transitions INTO a live status. We don't reset
     *      it on subsequent live → live transitions because that
     *      breaks the time-to-live calculation.
     */
    private function persist(
        PartnerSiteListing $row,
        PartnerDistributionResult $result,
        bool $markWentLiveOnSuccess = false,
    ): void {
        if (! $result->ok) {
            return;
        }

        $updates = [];

        if ($result->nextStatus !== null) {
            $previousStatus = $row->status?->value;
            $newStatus = $result->nextStatus->value;

            $updates['status'] = $newStatus;

            if ($markWentLiveOnSuccess
                && $newStatus === 'live'
                && $previousStatus !== 'live'
                && $row->went_live_at === null) {
                $updates['went_live_at'] = now();
            }
        }

        if ($result->externalListingId !== null) {
            $updates['external_listing_id'] = $result->externalListingId;
        }
        if ($result->externalUrl !== null) {
            $updates['external_url'] = $result->externalUrl;
        }
        if ($result->rejectionReason !== null) {
            $updates['rejection_reason'] = $result->rejectionReason;
        }
        if ($result->viewCount !== null) {
            $updates['view_count'] = $result->viewCount;
        }
        if ($result->inquiryCount !== null) {
            $updates['inquiry_count'] = $result->inquiryCount;
        }

        if (! empty($updates)) {
            $row->forceFill($updates)->save();
        }
    }
}
