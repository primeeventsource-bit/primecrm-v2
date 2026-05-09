<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\DTOs;

use App\Modules\Lead\Domain\Models\Lead;

/**
 * Result of a dedup lookup.
 *
 *   $isDuplicate     — strict match on (tenant_id, phone_hash) — schema
 *                      uniqueness already prevents the duplicate insert,
 *                      but we want to know about it BEFORE attempting
 *                      to persist so we can update the existing lead
 *                      instead of throwing.
 *   $existingLead    — the matched existing lead, when found.
 *   $matchType       — 'phone_exact' | 'email_exact' | 'fuzzy_name' | null.
 *   $confidence      — 0.0–1.0 score for soft matches.
 */
final class DedupResult
{
    public function __construct(
        public readonly bool $isDuplicate,
        public readonly ?Lead $existingLead = null,
        public readonly ?string $matchType = null,
        public readonly float $confidence = 0.0,
    ) {}

    public static function notDuplicate(): self
    {
        return new self(false);
    }

    public static function exactPhone(Lead $lead): self
    {
        return new self(true, $lead, 'phone_exact', 1.0);
    }

    public static function exactEmail(Lead $lead): self
    {
        return new self(true, $lead, 'email_exact', 0.95);
    }

    public static function fuzzyName(Lead $lead, float $confidence): self
    {
        return new self(true, $lead, 'fuzzy_name', $confidence);
    }
}
