<?php

declare(strict_types=1);

use App\Modules\Lead\Http\Controllers\LeadAssignmentController;
use App\Modules\Lead\Http\Controllers\LeadController;
use App\Modules\Lead\Http\Controllers\LeadImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lead Module Routes
|--------------------------------------------------------------------------
| All routes mount under /api and require auth + tenant resolution.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('leads')->group(function (): void {
        Route::get('/', [LeadController::class, 'index']);
        Route::post('/', [LeadController::class, 'store']);

        // Imports — supervisor only (gated by ImportLeadsRequest::authorize).
        Route::get('/imports', [LeadImportController::class, 'index']);
        Route::post('/import', [LeadImportController::class, 'store']);
        Route::get('/imports/{id}', [LeadImportController::class, 'show']);

        Route::get('/{id}', [LeadController::class, 'show']);
        Route::put('/{id}', [LeadController::class, 'update']);
        Route::patch('/{id}', [LeadController::class, 'update']);
        Route::delete('/{id}', [LeadController::class, 'destroy']);

        Route::post('/{id}/assign', [LeadAssignmentController::class, 'assign']);
    });
});
