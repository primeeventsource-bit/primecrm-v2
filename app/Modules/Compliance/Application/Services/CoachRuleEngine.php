<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

/**
 * Deterministic coaching rules — runs after the phrase scanner.
 *
 * The §6 priority order is:
 *   1. Compliance rescue   (the agent just crossed a line)
 *   2. Objection handling  (the owner just objected)
 *   3. Value proposition   (lull in conversation)
 *   4. Closing techniques  (owner is warming up)
 *
 * The rules engine evaluates 1 → 4 and returns the first match.
 * Compliance rescue is also detected by ProhibitedPhraseScanner;
 * THIS engine produces the *script* the agent can read aloud to
 * recover. The two services together cover both "what's wrong"
 * (scanner) and "what to say next" (this engine).
 *
 * When a real LLM call is wired (D8 follow-up), the model's hint
 * REPLACES the rule's hint UNLESS the rule produced a compliance
 * rescue — in that case we always prefer the deterministic
 * scripted recovery over a model-generated one, because the line
 * we want the agent to read aloud has to be defensible to the AG.
 */
final class CoachRuleEngine
{
    /**
     * @param  array<int, array{match: string, severity: string, reason: string, suggestion: string, offset: int, length: int}>  $matches
     */
    public function nextHint(string $utterance, array $matches): array
    {
        // 1. Compliance rescue — the loudest signal wins.
        $blocking = array_values(array_filter($matches, fn ($m) => $m['severity'] === 'block'));
        if (! empty($blocking)) {
            $first = $blocking[0];

            return [
                'priority' => 'compliance_rescue',
                'red_zone' => true,
                'hint' => 'STOP. '.$first['suggestion'],
                'rationale' => $first['reason'],
                'matches' => $matches,
            ];
        }

        $warning = array_values(array_filter($matches, fn ($m) => $m['severity'] === 'warn'));
        if (! empty($warning)) {
            $first = $warning[0];

            return [
                'priority' => 'compliance_caution',
                'red_zone' => false,
                'hint' => 'CAUTION: '.$first['suggestion'],
                'rationale' => $first['reason'],
                'matches' => $matches,
            ];
        }

        // 2. Objection handling — pattern-match common timeshare-owner
        //    pushbacks and surface the documented response.
        $lower = strtolower($utterance);

        foreach (self::OBJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern['regex'], $lower)) {
                return [
                    'priority' => 'objection',
                    'red_zone' => false,
                    'hint' => $pattern['hint'],
                    'rationale' => $pattern['rationale'],
                    'matches' => [],
                ];
            }
        }

        // 3. Value proposition — surfaces when the conversation is
        //    quiet (short utterance, no question, no commitment cue).
        if (str_word_count($utterance) < 8) {
            $value = self::VALUE_HINTS[array_rand(self::VALUE_HINTS)];

            return [
                'priority' => 'value',
                'red_zone' => false,
                'hint' => $value,
                'rationale' => 'Owner is quiet — surface a documented service input.',
                'matches' => [],
            ];
        }

        // 4. Closing — owner is warming up; nudge toward commitment.
        if (preg_match('/\b(yes|sure|ok(ay)?|sounds good|makes sense|why not)\b/i', $utterance)) {
            return [
                'priority' => 'close',
                'red_zone' => false,
                'hint' => 'CLOSE: "Great — would you like me to walk through the agreement and capture the disclosures now, or would you prefer a verifier call back tomorrow?"',
                'rationale' => 'Owner signaled assent — present the close path.',
                'matches' => [],
            ];
        }

        // Fallback — keep the agent moving without inventing pressure.
        return [
            'priority' => 'default',
            'red_zone' => false,
            'hint' => 'Confirm what you heard, then ask one clarifying question about the week they want listed.',
            'rationale' => 'No specific signal — keep discovery moving.',
            'matches' => [],
        ];
    }

    /**
     * @var array<int, array{regex: string, hint: string, rationale: string}>
     */
    private const OBJECTION_PATTERNS = [
        [
            'regex' => '/\b(unrented|hasn\'t rented|never rents|sitting empty)\b/',
            'hint' => 'OBJECTION: "I hear that — and that\'s exactly why most of our owners come to us. They were trying to list it themselves on one site. We push to 5+ partner channels with professional photos. We can\'t promise a renter, but we can promise the reach."',
            'rationale' => 'Owner is venting about prior failure — validate, then reframe to our service inputs.',
        ],
        [
            'regex' => '/\b(competitor|other compan(y|ies)|someone else|cheaper|free listing)\b/',
            'hint' => 'OBJECTION: "Most competitors either charge per rental (so you only pay when it works) or charge nothing upfront (so they have no skin in the game). We invest in marketing upfront, which is why we charge upfront. Happy to compare specific sites if you want."',
            'rationale' => 'Owner is comparing — explain the model honestly, no guarantees.',
        ],
        [
            'regex' => '/\b(catch|scam|too good|red flag|sketchy)\b/',
            'hint' => 'OBJECTION: "Fair question. The catch is what I told you: there\'s no guarantee of a rental. We do the marketing work; we cannot promise an outcome. If you want, I can walk you through our refund policy and the recorded-call compliance process so you see exactly what you\'re getting."',
            'rationale' => 'Trust signal — meet it with full transparency and offer the documentation.',
        ],
        [
            'regex' => '/\b(why upfront|why pay first|after it rents|when it rents)\b/',
            'hint' => 'OBJECTION: "We invest in your listing before any renter shows up — photos, multi-site distribution, inquiry handling. That work happens whether a rental closes or not, which is why the fee comes upfront. The fee covers the marketing service, not the result."',
            'rationale' => 'Reframe upfront fee as service cost, not outcome bet.',
        ],
        [
            'regex' => '/\b(think about it|talk to (my )?(wife|husband|spouse|partner)|call (you )?back)\b/',
            'hint' => 'OBJECTION: "Of course. While you\'re thinking, can I send you a one-page summary of the service and the refund policy? That way you and your [spouse/partner] are looking at the same details when you talk."',
            'rationale' => 'Don\'t pressure — offer documentation, set a follow-up window.',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const VALUE_HINTS = [
        'VALUE: We push to 5+ partner sites (Airbnb, Vrbo, RedWeek, etc.) — total reach is the service input we can guarantee.',
        'VALUE: We handle inquiry response within 4 hours — owners typically don\'t have time to monitor 5 inboxes themselves.',
        'VALUE: Every call that takes a listing fee is recorded with mandatory disclosures — fully audit-ready.',
        'VALUE: Professional photos + standardized listing copy across all partner sites — consistent presence drives more traffic.',
        'VALUE: You set the asking price and reserve; we don\'t accept rentals below your floor. You stay in control.',
    ];
}
