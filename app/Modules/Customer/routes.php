<?php

declare(strict_types=1);

use App\Modules\Customer\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('customers')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::patch('/{id}', [CustomerController::class, 'update']);
        Route::put('/{id}', [CustomerController::class, 'update']);
    });
});
