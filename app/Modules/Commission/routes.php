<?php

declare(strict_types=1);

use App\Modules\Commission\Http\Controllers\AdjustmentController;
use App\Modules\Commission\Http\Controllers\PayoutController;
use App\Modules\Commission\Http\Controllers\PlanController;
use App\Modules\Commission\Http\Controllers\RuleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('commission/plans')->group(function (): void {
        Route::get('/', [PlanController::class, 'index']);
        Route::post('/', [PlanController::class, 'store']);
        Route::get('/{id}', [PlanController::class, 'show'])->whereUuid('id');
        Route::patch('/{id}', [PlanController::class, 'update'])->whereUuid('id');
        Route::delete('/{id}', [PlanController::class, 'destroy'])->whereUuid('id');

        // Nested rules
        Route::post('/{planId}/rules', [RuleController::class, 'store'])->whereUuid('planId');
        Route::patch('/{planId}/rules/{ruleId}', [RuleController::class, 'update'])
            ->whereUuid('planId')->whereUuid('ruleId');
        Route::delete('/{planId}/rules/{ruleId}', [RuleController::class, 'destroy'])
            ->whereUuid('planId')->whereUuid('ruleId');
    });

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
