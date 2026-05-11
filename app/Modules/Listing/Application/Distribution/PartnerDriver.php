<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Distribution;

use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;

/**
 * The contract every partner-site integration implements.
 *
 * Concrete drivers live in Drivers/ and are wired in
 * ListingServiceProvider via the slug → class map. To add a new real
 * integration:
 *
 *   1. Implement this interface (e.g., AirbnbDriver) calling the
 *      partner's actual API.
 *   2. Register the binding in ListingServiceProvider::register().
 *   3. Set partner_sites.slug to match the registered key.
 *   4. Configure credentials in partner_sites.config (encrypted).
 *
 * The MockPartnerDriver is the default fallback — used for any site
 * that doesn't have a registered concrete driver yet, so the demo
 * flow works end-to-end while real integrations land one at a time.
 */
interface PartnerDriver
{
    public function push(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult;

    public function pause(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult;

    public function resume(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult;

    public function sync(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult;
}
