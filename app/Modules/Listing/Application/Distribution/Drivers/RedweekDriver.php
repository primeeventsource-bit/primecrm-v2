<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Distribution\Drivers;

use App\Modules\Listing\Application\Distribution\PartnerDistributionResult;
use App\Modules\Listing\Application\Distribution\PartnerDriver;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;

/**
 * RedWeek integration — placeholder.
 *
 * RedWeek is the most timeshare-focused of the partner sites and the
 * one most likely to ship as the "real" integration first. The
 * scaffolding lives here; the actual API calls land when the team
 * has credentials + a sandbox account.
 *
 * Until then, this class delegates to MockPartnerDriver so the demo
 * flow works against RedWeek as if it were live. Swap out the
 * delegation with real HTTP calls later — the public method
 * signatures (and their result-shape contract) stay identical.
 *
 *   public function push(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
 *   {
 *       $response = $this->httpClient
 *           ->withToken($site->config['api_token'] ?? null)
 *           ->post(rtrim($site->api_endpoint, '/').'/v2/listings', [
 *               'title' => $listing->property->resort_name,
 *               'check_in' => $listing->check_in_date,
 *               // ... map our fields to RedWeek's schema
 *           ]);
 *       // ... translate response into PartnerDistributionResult
 *   }
 */
final class RedweekDriver implements PartnerDriver
{
    public function __construct(private readonly MockPartnerDriver $fallback) {}

    public function push(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        // TODO: replace with real RedWeek API call once credentials land.
        return $this->fallback->push($listing, $site, $row);
    }

    public function pause(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        return $this->fallback->pause($listing, $site, $row);
    }

    public function resume(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        return $this->fallback->resume($listing, $site, $row);
    }

    public function sync(Listing $listing, PartnerSite $site, PartnerSiteListing $row): PartnerDistributionResult
    {
        return $this->fallback->sync($listing, $site, $row);
    }
}
