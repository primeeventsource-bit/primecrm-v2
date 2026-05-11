<?php

declare(strict_types=1);

use App\Core\Shared\Http\Controllers\InertiaPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Inertia)
|--------------------------------------------------------------------------
| Public landing → /login. Everything else requires auth:sanctum + tenant.
| Cloud's health probe hits /up (configured in bootstrap/app.php).
*/

Route::get('/', fn () => redirect('/dashboard'));

Route::get('/login', [InertiaPageController::class, 'login'])->name('login');

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/dashboard', [InertiaPageController::class, 'dashboard'])->name('dashboard');
    Route::get('/dialer/console', [InertiaPageController::class, 'dialerConsole'])->name('dialer.console');
    Route::get('/leads', [InertiaPageController::class, 'leadsIndex'])->name('leads.index');
    Route::get('/leads/{id}', [InertiaPageController::class, 'leadShow'])->name('leads.show')
        ->whereUuid('id');
    Route::get('/customers', [InertiaPageController::class, 'customersIndex'])->name('customers.index');
    Route::get('/customers/{id}', [InertiaPageController::class, 'customerShow'])->name('customers.show')
        ->whereUuid('id');

    // Timeshare-listing customer-service screen. The "owner" is a Lead
    // row; this view aggregates properties + listings + bookings +
    // financial ledger for the post-sale relationship.
    Route::get('/owners/{id}', [InertiaPageController::class, 'ownerShow'])->name('owners.show')
        ->whereUuid('id');

    // Listings management hub + detail (post-sale operational view).
    Route::get('/listings', [InertiaPageController::class, 'listingsIndex'])->name('listings.index');
    Route::get('/listings/{id}', [InertiaPageController::class, 'listingShow'])->name('listings.show')
        ->whereUuid('id');
    Route::get('/agents', [InertiaPageController::class, 'agentsIndex'])->name('agents.index');
    Route::get('/pipeline', [InertiaPageController::class, 'pipeline'])->name('pipeline.index');
    Route::get('/booking/search', [InertiaPageController::class, 'bookingSearch'])->name('booking.search');
    Route::get('/payment/capture', [InertiaPageController::class, 'paymentCapture'])->name('payment.capture');
    Route::get('/commission/payouts', [InertiaPageController::class, 'commissionPayouts'])->name('commission.payouts');

    // Supervisor-only — gated inside the controller.
    Route::get('/supervisor/war-room', [InertiaPageController::class, 'warRoom'])->name('supervisor.war_room');
    Route::get('/compliance/dnc', [InertiaPageController::class, 'complianceDnc'])->name('compliance.dnc');
    Route::get('/partner-sites', [InertiaPageController::class, 'partnerSites'])->name('partner_sites.index');

    // Renter-side bookings ledger (D5).
    Route::get('/bookings', [InertiaPageController::class, 'bookingsLedger'])->name('bookings.ledger');
});
