<?php

declare(strict_types=1);

namespace App\Modules\Listing;

use Illuminate\Support\ServiceProvider;

/**
 * Listing module — the post-sale fulfillment side of the business.
 *
 * Owns:
 *   - Property        (the timeshare itself)
 *   - Listing         (a marketed offering)
 *   - PartnerSite     (Airbnb / Vrbo / RedWeek / etc)
 *   - PartnerSiteListing (junction: per-site distribution row)
 *   - RentalInquiry   (a renter expressing interest)
 *
 * Booking lifecycle is shared with the existing Booking module — a
 * RentalInquiry that is accepted writes a row into `bookings` (now
 * augmented with renter-side fields).
 */
final class ListingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
