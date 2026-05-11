<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Call-floor surface (D-pre / Floor OS).
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');
    Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('api.dashboard.activity');
    Route::get('/dashboard/sparkline', [DashboardController::class, 'sparkline'])->name('api.dashboard.sparkline');
    Route::get('/dashboard/floor-status', [DashboardController::class, 'floorStatus'])->name('api.dashboard.floor_status');

    // Listing-service health surface (D7).
    Route::get('/dashboard/listing-health', [DashboardController::class, 'listingHealth'])
        ->name('api.dashboard.listing_health');
    Route::get('/dashboard/partner-health', [DashboardController::class, 'partnerHealth'])
        ->name('api.dashboard.partner_health');
    Route::get('/dashboard/booking-pipeline', [DashboardController::class, 'bookingPipeline'])
        ->name('api.dashboard.booking_pipeline');
    Route::get('/dashboard/compliance-posture', [DashboardController::class, 'compliancePosture'])
        ->name('api.dashboard.compliance_posture');
    Route::get('/dashboard/owner-signals', [DashboardController::class, 'ownerSignals'])
        ->name('api.dashboard.owner_signals');
});
