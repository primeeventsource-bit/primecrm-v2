<?php

declare(strict_types=1);

use App\Modules\Listing\Http\Controllers\ListingController;
use App\Modules\Listing\Http\Controllers\OwnerController;
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
});
