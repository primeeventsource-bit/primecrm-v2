<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Module Routes
|--------------------------------------------------------------------------
| Loaded via the ModuleServiceProvider with the 'api' middleware group
| and 'api' prefix. Module-specific routes here are mounted under /api.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [\App\Modules\Tenant\Http\Controllers\AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('/logout', [\App\Modules\Tenant\Http\Controllers\AuthController::class, 'logout']);
        Route::get('/me', [\App\Modules\Tenant\Http\Controllers\AuthController::class, 'me']);
    });
});

/*
 * Agents (users) — supervisor-gated CRUD for adding sales floor staff.
 */
Route::middleware(['auth:sanctum', 'tenant'])->prefix('agents')->group(function (): void {
    Route::get('/', [\App\Modules\Tenant\Http\Controllers\AgentController::class, 'index']);
    Route::post('/', [\App\Modules\Tenant\Http\Controllers\AgentController::class, 'store']);
    Route::get('/{id}', [\App\Modules\Tenant\Http\Controllers\AgentController::class, 'show']);
    Route::patch('/{id}', [\App\Modules\Tenant\Http\Controllers\AgentController::class, 'update']);
    Route::put('/{id}', [\App\Modules\Tenant\Http\Controllers\AgentController::class, 'update']);
});
