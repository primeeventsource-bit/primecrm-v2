<?php

declare(strict_types=1);

use App\Modules\Commission\Http\Controllers\AdjustmentController;
use App\Modules\Commission\Http\Controllers\PayoutController;
use App\Modules\Commission\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('commission/plans', [PlanController::class, 'index']);

    Route::prefix('commission/payouts')->group(function (): void {
        Route::get('/', [PayoutController::class, 'index']);
        Route::post('/build', [PayoutController::class, 'build']);
        Route::get('/{id}', [PayoutController::class, 'show']);
        Route::get('/{id}/calculations', [PayoutController::class, 'calculations']);
        Route::post('/{id}/approve', [PayoutController::class, 'approve']);
        Route::post('/{id}/mark-paid', [PayoutController::class, 'markPaid']);
    });

    Route::prefix('commission/adjustments')->group(function (): void {
        Route::get('/', [AdjustmentController::class, 'index']);
        Route::post('/', [AdjustmentController::class, 'store']);
    });
});
