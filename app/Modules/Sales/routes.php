<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\DealController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('deals')->group(function (): void {
        Route::get('/', [DealController::class, 'index']);
        Route::post('/', [DealController::class, 'store']);
        Route::get('/{id}', [DealController::class, 'show']);
        Route::post('/{id}/advance-stage', [DealController::class, 'advanceStage']);
    });
});
