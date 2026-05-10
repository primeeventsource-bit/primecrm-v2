<?php

declare(strict_types=1);

use App\Modules\Note\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('notes')->group(function (): void {
        Route::get('/', [NoteController::class, 'index']);
        Route::post('/', [NoteController::class, 'store']);
        Route::delete('/{id}', [NoteController::class, 'destroy']);
    });
});
