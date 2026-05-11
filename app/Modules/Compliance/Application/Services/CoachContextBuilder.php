<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Sales\Domain\Models\Deal;

/**
 * Builds the domain-aware system prompt for the AI Live Coach.
 *
 * Per §6 of the build-out spec — the coach is timeshare-listing-aware
 * and compliance-first. The prompt below is the production text;
 * keep it verbatim with the spec so reviewers can diff against §6
 * without translation.
 *
 * The builder produces TWO artifacts:
 *
 *   systemPrompt()  The static base — what the agent is selling,
 *                   what they're not, the compliance lines.
 *   callContext()   A short situational block injected per-call:
 *                   owner name, listing fee, property, current
 *                   stage. Lets the model reference specifics
 *                   without hallucinating them.
 *
 * The actual LLM call (Anthropic / OpenAI) lives in
 * LiveCoachController and may be replaced; the prompt assembly here
 * is the part the compliance team reviews.
 */
final class CoachContextBuilder
{
    /**
     * Static system prompt. Verbatim alignment with §6 of the spec.
     */
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        SYSTEM CONTEXT:
        - The agent is selling a LISTING FEE for a timeshare RENTAL MARKETING service.
        - The agent is NOT selling the timeshare itself, NOT buying the timeshare, NOT promising a renter.
        - The agent IS selling: "We will list your unused weeks on multiple high-traffic sites and try to find a renter."
        - The fee is non-refundable after the documented cooling-off window.
        - Compliance rules:
          - Never imply a rental is guaranteed
          - Never imply we have buyers waiting
          - Always disclose recording, no-guarantee, total fee, refund policy
          - Flag any agent statement that approaches misrepresentation

        When generating coaching hints, prioritize in order:
          1. COMPLIANCE RESCUE. If the agent crossed a regulatory line
             (rental guarantees, buyers-waiting, money-back-on-no-rental,
             we-buy-or-sell), interrupt with a course-correct script
             the agent can read out loud verbatim.
          2. OBJECTION HANDLING specific to timeshare owners. Common
             objections: "It's gone unrented for 2 years already" /
             "How is this different from [competitor]?" / "What's the
             catch?" / "Why upfront fee?".
          3. VALUE PROPOSITION. Multi-site reach (5+ major partners),
             professional photos, automated inquiry handling,
             documented compliance posture — none of these are
             outcome guarantees, they are documented service inputs.
          4. CLOSING TECHNIQUES for an upfront-fee model. Urgency
             must be substantively justified (e.g., proximity to
             check-in date), not manufactured. Comparison framing
             ("vs. listing it yourself") works; scarcity framing
             ("only N slots left this week") does not.

        Response shape:
        Return ONE concise hint (≤ 280 chars). If a compliance
        violation occurred, prefix with "STOP." and provide a
        course-correct script. Otherwise prefix with the priority
        category (OBJECTION / VALUE / CLOSE).
        PROMPT;
    }

    /**
     * Per-call situational context. Pulled from the live call/deal/lead
     * so the model can reference specifics without inventing them.
     *
     * Returns null when we don't have enough data to be useful — the
     * LLM should fall back to generic guidance rather than make up
     * details about the owner.
     */
    public function callContext(Call $call, ?Deal $deal = null, ?Lead $lead = null): ?string
    {
        $lead ??= $call->lead_id ? Lead::withoutTenantScope()->find($call->lead_id) : null;
        if ($lead === null) {
            return null;
        }

        $name = trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: '(unnamed owner)';
        $location = trim(implode(', ', array_filter([$lead->city, $lead->state]))) ?: 'unknown location';

        $lines = [
            'CURRENT CALL:',
            "- Owner: {$name} ({$location})",
        ];

        if ($lead->resort_interest) {
            $lines[] = "- Resort interest: {$lead->resort_interest}";
        }
        if ($lead->property_type) {
            $lines[] = '- Property type: '.str_replace('_', ' ', (string) $lead->property_type);
        }

        if ($deal) {
            $stage = $deal->stage?->value ?? 'unknown';
            $lines[] = "- Sales stage: {$stage}";
            if ($deal->listing_fee > 0) {
                $lines[] = '- Proposed listing fee: $'.number_format((float) $deal->listing_fee, 2);
            }
            if (! $deal->tcpa_disclosure_completed) {
                $lines[] = '- ⚠ TCPA disclosures not yet captured on this call';
            }
        }

        // Prior-context cue — a single line reminding the model of the
        // owner's posture so the hint matches their state of mind.
        $lines[] = '';
        $lines[] = 'Coach the agent with this owner specifically in mind. '
            .'Reference the resort/location/stage above only if relevant; never invent details.';

        return implode("\n", $lines);
    }
}
