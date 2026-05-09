<?php

declare(strict_types=1);

use App\Modules\CallCenter\Http\Controllers\AgentStatusController;
use App\Modules\CallCenter\Http\Controllers\CallController;
use App\Modules\CallCenter\Http\Controllers\SupervisorCallController;
use App\Modules\CallCenter\Http\Controllers\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CallCenter Module Routes
|--------------------------------------------------------------------------
| Most routes are tenant-scoped. Webhook endpoints are PUBLIC — Twilio
| has no auth token, signature verification handles authenticity.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('calls')->group(function (): void {
        Route::get('/', [CallController::class, 'index']);
        Route::get('/{id}', [CallController::class, 'show']);
        Route::post('/{id}/disposition', [CallController::class, 'disposition']);
        Route::post('/{id}/end', [CallController::class, 'end']);
    });

    Route::prefix('agent-status')->group(function (): void {
        Route::get('/', [AgentStatusController::class, 'index']);
        Route::get('/me', [AgentStatusController::class, 'me']);
        Route::post('/', [AgentStatusController::class, 'set']);
        Route::post('/heartbeat', [AgentStatusController::class, 'heartbeat']);
    });

    Route::prefix('supervisor/calls')->group(function (): void {
        Route::post('/{id}/kill', [SupervisorCallController::class, 'kill']);
        Route::post('/{id}/whisper', [SupervisorCallController::class, 'whisper']);
        Route::post('/{id}/barge', [SupervisorCallController::class, 'barge']);
    });
});

/*
 * Twilio webhooks — PUBLIC. Signature verification is enforced inside
 * the controller, not at middleware level, so we can capture rejections
 * (and their payloads) for forensic logging without changing route shape.
 */
Route::prefix('webhooks/twilio')->group(function (): void {
    Route::post('/voice/{callId}', [TwilioWebhookController::class, 'voice']);
    Route::post('/status/{callId}', [TwilioWebhookController::class, 'status']);
    Route::post('/recording/{callId}', [TwilioWebhookController::class, 'recording']);
});
