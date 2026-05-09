<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\ConsentController;
use App\Modules\Compliance\Http\Controllers\DncController;
use App\Modules\Compliance\Http\Controllers\GuardrailController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Compliance Module Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('compliance')->group(function (): void {
        Route::get('/dnc', [DncController::class, 'index']);
        Route::post('/dnc', [DncController::class, 'store']);
        Route::delete('/dnc/{id}', [DncController::class, 'destroy']);

        Route::get('/consent', [ConsentController::class, 'index']);
        Route::post('/consent', [ConsentController::class, 'store']);
        Route::post('/consent/{id}/revoke', [ConsentController::class, 'revoke']);

        Route::get('/guardrail/check/{leadId}', [GuardrailController::class, 'check']);
    });
});
