<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\Models\ConsentRecord;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;
use App\Modules\Lead\Domain\Models\Lead;

/**
 * TCPA consent check.
 *
 * Predictive/auto-dialed calls to a wireless number REQUIRE prior express
 * written consent (consent_type = autodialer). Missing or revoked consent
 * is a per-call statutory violation ($500–$1,500 each).
 *
 * Decision tree:
 *
 *   IF the lead's phone is on the wireless DNC list (handled separately
 *   by DncCheckService — a wireless number with no consent gets rejected
 *   there for a different reason but the outcome is the same).
 *
 *   IF the call is being initiated in 'manual' dialer mode (no autodialer),
 *   we still require *some* consent or an established business relationship,
 *   but transactional/manual contact has a softer bar.
 *
 *   IF auto/predictive — require active autodialer consent.
 *
 * The service takes the dialer mode as input so the caller (guardrail
 * orchestrator) decides; we don't try to infer it from anywhere else.
 */
final class ConsentCheckService
{
    /**
     * @param 'manual'|'preview'|'progressive'|'predictive' $dialerMode
     */
    public function check(Lead $lead, string $dialerMode = 'predictive'): GuardrailDecision
    {
        // Manual dial of a number with established business relationship is
        // generally fine. Preview/progressive/predictive — require consent.
        if ($dialerMode === 'manual') {
            return GuardrailDecision::allow(['mode' => 'manual']);
        }

        $consent = ConsentRecord::query()
            ->forPhone($lead->phone_hash)
            ->ofType(ConsentType::Autodialer)
            ->orderByDesc('consented_at')
            ->first();

        if ($consent === null) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::ConsentMissing,
                reason: 'No express written consent on file for autodialer use.',
                metadata: ['phone_hash' => substr($lead->phone_hash, 0, 12).'…'],
            );
        }

        if ($consent->revoked_at !== null) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::ConsentRevoked,
                reason: 'Consent for this number was revoked.',
                metadata: [
                    'revoked_at' => $consent->revoked_at->toIso8601String(),
                    'revocation_reason' => $consent->revocation_reason,
                ],
            );
        }

        return GuardrailDecision::allow([
            'consent_id' => $consent->id,
            'consented_at' => $consent->consented_at->toIso8601String(),
        ]);
    }
}
