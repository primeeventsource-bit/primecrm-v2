<?php

declare(strict_types=1);

use App\Modules\Booking\Http\Controllers\BookingController;
use App\Modules\Booking\Http\Controllers\HoldController;
use App\Modules\Booking\Http\Controllers\InventoryController;
use App\Modules\Booking\Http\Controllers\InventoryManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/inventory/search', [InventoryController::class, 'search']);

    // Inventory write-side: add a single row, or bulk-import via
    // CSV/Excel. Pickers feed the singular form's typeaheads. The
    // bulk flow is two-step (preview → import) so the operator can
    // approve which new resorts/units we'd create.
    Route::prefix('inventory')->group(function (): void {
        Route::get('/resorts-picker', [InventoryManagementController::class, 'resortsPicker'])
            ->name('api.inventory.resorts_picker');
        Route::get('/units-picker', [InventoryManagementController::class, 'unitsPicker'])
            ->name('api.inventory.units_picker');
        Route::post('/availability', [InventoryManagementController::class, 'store'])
            ->name('api.inventory.availability.store');
        Route::get('/template.csv', [InventoryManagementController::class, 'template'])
            ->name('api.inventory.template');
        Route::post('/bulk-preview', [InventoryManagementController::class, 'bulkPreview'])
            ->name('api.inventory.bulk_preview');
        Route::post('/bulk-import', [InventoryManagementController::class, 'bulkImport'])
            ->name('api.inventory.bulk_import');
    });

    Route::prefix('inventory/holds')->group(function (): void {
        Route::post('/', [HoldController::class, 'store']);
        Route::delete('/{id}', [HoldController::class, 'release']);
    });

    Route::prefix('bookings')->group(function (): void {
        Route::get('/', [BookingController::class, 'index']);
        Route::post('/from-hold/{holdId}', [BookingController::class, 'confirmFromHold']);
        Route::get('/{id}', [BookingController::class, 'show']);
        Route::post('/{id}/cancel', [BookingController::class, 'cancel']);
    });
});
