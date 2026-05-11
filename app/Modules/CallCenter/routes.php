<?php

declare(strict_types=1);

use App\Modules\CallCenter\Http\Controllers\AgentStatusController;
use App\Modules\CallCenter\Http\Controllers\CallController;
use App\Modules\CallCenter\Http\Controllers\PrimeConnectAccessTokenController;
use App\Modules\CallCenter\Http\Controllers\PrimeConnectGuestController;
use App\Modules\CallCenter\Http\Controllers\PrimeConnectGuestTokenController;
use App\Modules\CallCenter\Http\Controllers\PrimeConnectRoomController;
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

    /*
     * Prime Connect (video) — lobby + in-call REST surface. Lives inside
     * the CallCenter module because rooms are a `medium = 'video'`
     * variant of the same calls table; see 2026_05_09_000300_extend_calls_for_video.
     */
    Route::prefix('prime-connect')->group(function (): void {
        Route::post('/access-token', [PrimeConnectAccessTokenController::class, 'store']);

        Route::prefix('rooms')->group(function (): void {
            Route::get('/', [PrimeConnectRoomController::class, 'index']);
            Route::post('/', [PrimeConnectRoomController::class, 'store']);
            Route::get('/{id}', [PrimeConnectRoomController::class, 'show']);
            Route::delete('/{id}', [PrimeConnectRoomController::class, 'destroy']);
            // War-room flag — agent flips this from the in-call UI to
            // signal supervisors that they want backup; supervisors can
            // also unflip from the war room view.
            Route::post('/{id}/flag', [PrimeConnectRoomController::class, 'flag']);
            // Customer-facing guest invite tokens. Staff mints / revokes;
            // public consumption lives outside this auth group.
            Route::post('/{id}/guest-tokens', [PrimeConnectGuestTokenController::class, 'store']);
            Route::delete('/{id}/guest-tokens/{tokenId}', [PrimeConnectGuestTokenController::class, 'destroy']);
        });
    });
});

/*
 * Prime Connect — PUBLIC guest endpoints. Bearer is the token itself
 * (it's in the URL); GuestTokenService::resolve gates access by token
 * lookup + expiry + revocation check, then sets TenantContext from the
 * row before any subsequent query runs.
 */
Route::prefix('prime-connect/guest')->group(function (): void {
    Route::get('/{token}', [PrimeConnectGuestController::class, 'show']);
    Route::post('/{token}/access-token', [PrimeConnectGuestController::class, 'accessToken']);
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
