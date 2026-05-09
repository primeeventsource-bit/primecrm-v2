<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Services;

use App\Modules\Lead\Domain\Models\Lead;

/**
 * Pure, deterministic, explainable lead scoring.
 *
 * The score is a weighted sum that produces a stable integer in
 * [0, max_score]. All weights are config-driven so business teams
 * can tune without code changes. The breakdown is preserved (return
 * value of {@see compute}) so the UI can show "why is this lead a 740?".
 *
 * This service is pure — it reads the lead and config, returns an integer.
 * Side effects (writing the score back, queueing reassignment) live in
 * {@see \App\Modules\Lead\Application\Jobs\ScoreLeadJob}.
 */
final class LeadScoringService
{
    /**
     * @return array{score: int, breakdown: array<string, int>}
     */
    public function compute(Lead $lead): array
    {
        $config = config('leads.scoring');

        $breakdown = [];

        // Priority is the strongest signal — set by the operator/source.
        $priorityKey = $lead->priority?->value ?? 'normal';
        $breakdown['priority'] = (int) ($config['priority_weights'][$priorityKey] ?? 0);

        // Express consent is gold — TCPA-clean leads are an order of magnitude
        // more valuable than cold ones.
        $breakdown['has_express_consent'] = $lead->has_express_consent
            ? (int) $config['has_express_consent_bonus']
            : 0;

        // Vacation rental specifics
        $breakdown['resort_interest_known'] = $lead->resort_interest !== null
            ? (int) $config['resort_interest_known_bonus']
            : 0;

        // Phone in E.164 (it always should be, but defensive)
        $breakdown['phone_e164'] = str_starts_with($lead->phone ?? '', '+')
            ? (int) $config['phone_e164_bonus']
            : 0;

        $breakdown['email_present'] = ($lead->email !== null && $lead->email !== '')
            ? (int) $config['email_present_bonus']
            : 0;

        // Source-specific weighting
        $sourceWeights = $config['source_weights'];
        $breakdown['source'] = (int) ($sourceWeights[$lead->source] ?? $sourceWeights['unknown'] ?? 0);

        // Estimated value: linear up to a cap
        if ($lead->estimated_value !== null && (float) $lead->estimated_value > 0) {
            $value = (int) min(
                (float) $config['estimated_value']['cap'],
                (float) $lead->estimated_value / (float) $config['estimated_value']['divisor'],
            );
            $breakdown['estimated_value'] = $value;
        } else {
            $breakdown['estimated_value'] = 0;
        }

        // Penalties — older leads with many failed attempts are worth less.
        $attemptPenalty = $lead->contact_attempts * (int) $config['attempt_penalty_per_call'];
        $breakdown['attempt_penalty'] = -$attemptPenalty;

        $ageInDays = $lead->created_at !== null
            ? (int) max(0, $lead->created_at->diffInDays(now()))
            : 0;
        $agePenalty = (int) min(
            (int) $config['max_age_penalty'],
            $ageInDays * (int) $config['age_penalty_per_day'],
        );
        $breakdown['age_penalty'] = -$agePenalty;

        $sum = (int) array_sum($breakdown);
        $clamped = (int) max(0, min((int) $config['max_score'], $sum));

        return [
            'score' => $clamped,
            'breakdown' => $breakdown,
        ];
    }
}
