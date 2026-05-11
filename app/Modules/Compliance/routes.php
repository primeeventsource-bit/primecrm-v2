<?php

declare(strict_types=1);

use App\Modules\Compliance\Http\Controllers\ChargebackCaseController;
use App\Modules\Compliance\Http\Controllers\ComplianceRecordingController;
use App\Modules\Compliance\Http\Controllers\ConsentController;
use App\Modules\Compliance\Http\Controllers\DncController;
use App\Modules\Compliance\Http\Controllers\GuardrailController;
use App\Modules\Compliance\Http\Controllers\ProhibitedPhraseController;
use App\Modules\Compliance\Http\Controllers\RefundCaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Compliance Module Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('compliance')->group(function (): void {
        // TCPA / DNC (existing)
        Route::get('/dnc', [DncController::class, 'index']);
        Route::post('/dnc', [DncController::class, 'store']);
        Route::delete('/dnc/{id}', [DncController::class, 'destroy']);

        Route::get('/consent', [ConsentController::class, 'index']);
        Route::post('/consent', [ConsentController::class, 'store']);
        Route::post('/consent/{id}/revoke', [ConsentController::class, 'revoke']);

        Route::get('/guardrail/check/{leadId}', [GuardrailController::class, 'check']);

        // Sales-disclosure compliance (D6)
        Route::get('/recordings', [ComplianceRecordingController::class, 'index'])
            ->name('api.compliance.recordings.index');
        Route::post('/recordings/{id}/transition', [ComplianceRecordingController::class, 'transition'])
            ->whereUuid('id')->name('api.compliance.recordings.transition');
        Route::post('/recordings/{id}/toggle', [ComplianceRecordingController::class, 'toggle'])
            ->whereUuid('id')->name('api.compliance.recordings.toggle');

        // Refund case workflow (D6)
        Route::get('/refund-cases', [RefundCaseController::class, 'index'])
            ->name('api.compliance.refund_cases.index');
        Route::post('/refund-cases', [RefundCaseController::class, 'store'])
            ->name('api.compliance.refund_cases.store');
        Route::post('/refund-cases/{id}/transition', [RefundCaseController::class, 'transition'])
            ->whereUuid('id')->name('api.compliance.refund_cases.transition');

        // Chargeback case workflow (D6)
        Route::get('/chargeback-cases', [ChargebackCaseController::class, 'index'])
            ->name('api.compliance.chargeback_cases.index');
        Route::post('/chargeback-cases/{id}/transition', [ChargebackCaseController::class, 'transition'])
            ->whereUuid('id')->name('api.compliance.chargeback_cases.transition');

        // Prohibited-phrase scanner (D6) — pre-save / pre-display gate
        // for any free-form text shown to or composed for owners.
        Route::post('/phrase-check', [ProhibitedPhraseController::class, 'check'])
            ->name('api.compliance.phrase_check');
    });
});
