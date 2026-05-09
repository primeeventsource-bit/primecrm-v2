<?php

declare(strict_types=1);

use App\Modules\Payment\Http\Controllers\PaymentController;
use App\Modules\Payment\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('payments')->group(function (): void {
        Route::get('/', [PaymentController::class, 'index']);
        Route::post('/charge', [PaymentController::class, 'charge']);
        Route::get('/{id}', [PaymentController::class, 'show']);
        Route::post('/{id}/refund', [PaymentController::class, 'refund']);
    });
});

/*
 * Stripe webhook — PUBLIC. Signature verified via the configured webhook
 * secret. Body MUST be raw (Laravel's JSON middleware would corrupt the
 * HMAC), so this route bypasses standard parsing by reading getContent()
 * inside the controller.
 */
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
