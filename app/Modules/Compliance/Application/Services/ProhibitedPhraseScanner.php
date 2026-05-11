<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

/**
 * Detects phrases that cross the "no-guarantee" doctrine line.
 *
 * Per §5.4 of the timeshare build-out spec: timeshare resale/rental
 * services are specifically named in FTC + state AG enforcement
 * actions, and the bright line is misrepresentation about rental
 * likelihood. We scan email/SMS templates, AI-coach suggestions,
 * and free-form agent text BEFORE it's saved or shown to the owner.
 *
 * The match list is intentionally conservative — false positives
 * are fine (reviewer just unblocks the text); false negatives are
 * not (one bad email = one AG complaint).
 *
 * Severities:
 *   block    Reject outright. The phrase is a clear-cut
 *            misrepresentation of rental likelihood or our role.
 *   warn     Likely problematic; surface for human judgement but
 *            don't auto-reject. Often legitimate in context (e.g.,
 *            quoting an inbound owner statement).
 */
final class ProhibitedPhraseScanner
{
    /**
     * @var array<int, array{pattern: string, severity: string, reason: string, suggestion: string}>
     */
    private const RULES = [
        // Direct rental-guarantee statements
        [
            'pattern' => '/\bguarantee(d|s)?\b.{0,40}\b(rent(al|s|ed|ing)?|book(ing|ed)?|sell)\b/i',
            'severity' => 'block',
            'reason' => 'Implies a guarantee that the timeshare will rent.',
            'suggestion' => 'Replace with: "We have a strong track record of placing rentals, though we cannot guarantee outcomes."',
        ],
        [
            'pattern' => '/\b(you|you\'ll|you will|you\'re going to|youll)\b.{0,30}\b(definitely|certainly|for sure|surely)\b.{0,30}\b(rent|book|sell)\b/i',
            'severity' => 'block',
            'reason' => 'Asserts a certainty about outcomes we cannot guarantee.',
            'suggestion' => 'Soften to: "We will work hard to find a renter, but cannot promise an outcome."',
        ],
        // "Buyer/renter waiting" claims
        [
            'pattern' => '/\b(have|got|several|multiple)\b.{0,20}\b(buyers?|renters?|clients?)\b.{0,30}\b(wait(ing|s)?|interested|lined up|ready)\b/i',
            'severity' => 'block',
            'reason' => 'Implies we have specific buyers/renters waiting — a classic timeshare-fraud red flag.',
            'suggestion' => 'Use: "Our partner sites receive thousands of searches each week from active travellers."',
        ],
        // Money-back assertions outside the cooling-off period
        [
            'pattern' => '/\b(money[\s\-]?back|full refund|guaranteed refund)\b.{0,40}\b(if|when|in case|should)\b.{0,30}\b(no|doesn\'t|does not|don\'t)\b.{0,20}\b(rent|book|sell)\b/i',
            'severity' => 'block',
            'reason' => 'Promises a refund tied to outcome — only the cooling-off-period refund is unconditional; everything else goes through the refund-case workflow.',
            'suggestion' => 'Reference the documented refund policy and cooling-off window instead.',
        ],
        // "We buy" or "we sell" — out of scope; we're a marketing service
        [
            'pattern' => '/\bwe\b.{0,15}\b(buy|purchas(e|ing)|sell)\b.{0,40}\b(your|the)\b.{0,20}\b(timeshare|week|property|unit)\b/i',
            'severity' => 'block',
            'reason' => 'Misrepresents our role — we market listings, we do not buy or sell the property.',
            'suggestion' => 'Clarify: "We market your week on multiple partner sites; the owner sets the price and we connect with potential renters."',
        ],
        // "Time-sensitive" / urgency-without-substance — softer warn
        [
            'pattern' => '/\b(act now|limited time|one[\s\-]?time offer|today only|won\'t last)\b/i',
            'severity' => 'warn',
            'reason' => 'High-pressure urgency phrasing draws regulator attention; needs substantive justification.',
            'suggestion' => 'If urgency is real (e.g., "your check-in date is within 60 days"), state the factual reason.',
        ],
        // Hidden-fee claims
        [
            'pattern' => '/\bno\b.{0,20}\b(hidden|additional|extra|other)\b.{0,15}\b(fees?|costs?|charges?)\b/i',
            'severity' => 'warn',
            'reason' => 'Statements about "no hidden fees" must match the executed agreement exactly.',
            'suggestion' => 'Restate the actual fee total instead of a negative claim.',
        ],
    ];

    /**
     * Scan text against the prohibited-phrase rules.
     *
     * @return array<int, array{match: string, severity: string, reason: string, suggestion: string, offset: int, length: int}>
     */
    public function scan(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $matches = [];

        foreach (self::RULES as $rule) {
            if (preg_match_all(
                $rule['pattern'],
                $text,
                $found,
                PREG_OFFSET_CAPTURE,
            )) {
                foreach ($found[0] as $hit) {
                    $matches[] = [
                        'match' => $hit[0],
                        'severity' => $rule['severity'],
                        'reason' => $rule['reason'],
                        'suggestion' => $rule['suggestion'],
                        'offset' => $hit[1],
                        'length' => strlen($hit[0]),
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * Convenience — returns true if the text contains any 'block'
     * severity match. For pre-save gates that should hard-fail.
     */
    public function hasBlocking(string $text): bool
    {
        foreach ($this->scan($text) as $m) {
            if ($m['severity'] === 'block') {
                return true;
            }
        }

        return false;
    }
}
