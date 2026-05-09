<?php

declare(strict_types=1);

use App\Modules\Booking\Http\Controllers\BookingController;
use App\Modules\Booking\Http\Controllers\HoldController;
use App\Modules\Booking\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/inventory/search', [InventoryController::class, 'search']);

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
