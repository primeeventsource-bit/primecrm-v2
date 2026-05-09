<?php

declare(strict_types=1);

use App\Modules\Dialer\Http\Controllers\DialerSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dialer Module Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('dialer')->group(function (): void {
        Route::get('/sessions/active', [DialerSessionController::class, 'active']);
        Route::post('/sessions', [DialerSessionController::class, 'start']);
        Route::post('/sessions/{id}/pause', [DialerSessionController::class, 'pause']);
        Route::post('/sessions/{id}/resume', [DialerSessionController::class, 'resume']);
        Route::post('/sessions/{id}/stop', [DialerSessionController::class, 'stop']);
        Route::post('/sessions/{id}/dial-now', [DialerSessionController::class, 'dialNow']);
    });
});
