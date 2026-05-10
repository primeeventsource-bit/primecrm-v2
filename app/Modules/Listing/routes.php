<?php

declare(strict_types=1);

use App\Modules\Listing\Http\Controllers\ListingController;
use App\Modules\Listing\Http\Controllers\ListingDistributionController;
use App\Modules\Listing\Http\Controllers\OwnerController;
use App\Modules\Listing\Http\Controllers\PartnerSiteController;
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

    // Partner-site config (settings page).
    Route::get('/partner-sites', [PartnerSiteController::class, 'index'])
        ->name('api.partner_sites.index');
    Route::get('/partner-sites/{id}', [PartnerSiteController::class, 'show'])
        ->whereUuid('id')
        ->name('api.partner_sites.show');
    Route::patch('/partner-sites/{id}', [PartnerSiteController::class, 'update'])
        ->whereUuid('id')
        ->name('api.partner_sites.update');
});
