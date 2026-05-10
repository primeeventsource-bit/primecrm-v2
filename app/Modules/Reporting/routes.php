<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
    Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('api.dashboard.activity');
    Route::get('/dashboard/sparkline', [DashboardController::class, 'sparkline'])->name('api.dashboard.sparkline');
    Route::get('/dashboard/floor-status', [DashboardController::class, 'floorStatus'])->name('api.dashboard.floor_status');
});
