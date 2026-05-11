<?php

declare(strict_types=1);

use App\Modules\Listing\Http\Controllers\ListingController;
use App\Modules\Listing\Http\Controllers\ListingDistributionController;
use App\Modules\Listing\Http\Controllers\OwnerController;
use App\Modules\Listing\Http\Controllers\PartnerSiteController;
use App\Modules\Listing\Http\Controllers\PartnerWebhookController;
use App\Modules\Listing\Http\Controllers\RentalBookingController;
use App\Modules\Listing\Http\Controllers\RentalInquiryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Listing Module Routes
|--------------------------------------------------------------------------
| Loaded via the ModuleServiceProvider with the 'api' middleware group
| and 'api' prefix. Module-specific routes here are mounted under /api.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Owner customer-service screen — single round-trip aggregate.
    Route::get('/owners/{id}/dossier', [OwnerController::class, 'dossier'])
        ->whereUuid('id')
        ->name('api.owners.dossier');

    // Listings management hub + detail.
    Route::get('/listings', [ListingController::class, 'index'])
        ->name('api.listings.index');
    Route::get('/listings/{id}', [ListingController::class, 'show'])
        ->whereUuid('id')
        ->name('api.listings.show');

    // Per-listing partner-site distribution actions. The driver
    // pattern (see Application\Distribution\PartnerDriver) decides
    // whether each request hits a real partner API or the mock
    // driver. Wired through ListingDistributor.
    Route::prefix('/listings/{listingId}/distributions')->whereUuid('listingId')->group(function (): void {
        Route::post('/', [ListingDistributionController::class, 'store'])
            ->name('api.listings.distributions.store');
        Route::post('/{rowId}/repush', [ListingDistributionController::class, 'repush'])
            ->whereUuid('rowId')->name('api.listings.distributions.repush');
        Route::post('/{rowId}/pause', [ListingDistributionController::class, 'pause'])
            ->whereUuid('rowId')->name('api.listings.distributions.pause');
        Route::post('/{rowId}/resume', [ListingDistributionController::class, 'resume'])
            ->whereUuid('rowId')->name('api.listings.distributions.resume');
        Route::post('/{rowId}/sync', [ListingDistributionController::class, 'sync'])
            ->whereUuid('rowId')->name('api.listings.distributions.sync');
    });

    // Partner-site config (settings page). Create + destroy + rotate
    // are supervisor-gated inside the controller; index/show/update are
    // open to anyone in the tenant.
    Route::get('/partner-sites', [PartnerSiteController::class, 'index'])
        ->name('api.partner_sites.index');
    Route::post('/partner-sites', [PartnerSiteController::class, 'store'])
        ->name('api.partner_sites.store');
    Route::get('/partner-sites/{id}', [PartnerSiteController::class, 'show'])
        ->whereUuid('id')
        ->name('api.partner_sites.show');
    Route::patch('/partner-sites/{id}', [PartnerSiteController::class, 'update'])
        ->whereUuid('id')
        ->name('api.partner_sites.update');
    Route::delete('/partner-sites/{id}', [PartnerSiteController::class, 'destroy'])
        ->whereUuid('id')
        ->name('api.partner_sites.destroy');
    Route::post('/partner-sites/{id}/rotate-secret', [PartnerSiteController::class, 'rotateSecret'])
        ->whereUuid('id')
        ->name('api.partner_sites.rotate_secret');
    Route::get('/partner-sites/{id}/webhook-events', [PartnerSiteController::class, 'webhookEvents'])
        ->whereUuid('id')
        ->name('api.partner_sites.webhook_events');

    // Renter inquiry actions (D5).
    Route::prefix('/rental-inquiries/{id}')->whereUuid('id')->group(function (): void {
        Route::post('/respond', [RentalInquiryController::class, 'respond'])
            ->name('api.rental_inquiries.respond');
        Route::post('/mark-lost', [RentalInquiryController::class, 'markLost'])
            ->name('api.rental_inquiries.mark_lost');
        Route::post('/book', [RentalInquiryController::class, 'book'])
            ->name('api.rental_inquiries.book');
    });

    // Renter-side bookings ledger (the success-metric view).
    Route::get('/rental-bookings', [RentalBookingController::class, 'index'])
        ->name('api.rental_bookings.index');
});

/*
 * PUBLIC partner webhook ingest. NO auth middleware — the HMAC
 * signature inside the controller is the auth boundary. The slug
 * identifies which partner_site (and therefore which tenant) the
 * payload belongs to; TenantContext is set from the row.
 *
 * Slug regex matches our internal slugify() output: lowercase
 * alphanumeric with internal dashes only.
 */
Route::prefix('/partner-webhooks')->group(function (): void {
    Route::post('/{slug}/inquiries', [PartnerWebhookController::class, 'inquiry'])
        ->where('slug', '[a-z0-9][a-z0-9-]*[a-z0-9]')
        ->name('api.partner_webhooks.inquiry');
    Route::post('/{slug}/bookings', [PartnerWebhookController::class, 'booking'])
        ->where('slug', '[a-z0-9][a-z0-9-]*[a-z0-9]')
        ->name('api.partner_webhooks.booking');
});
