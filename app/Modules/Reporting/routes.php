<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
});
