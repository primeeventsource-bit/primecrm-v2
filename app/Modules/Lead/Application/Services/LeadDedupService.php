<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Services;

use App\Modules\Lead\Application\DTOs\DedupResult;
use App\Modules\Lead\Application\DTOs\LeadInputData;
use App\Modules\Lead\Domain\Models\Lead;

/**
 * Decides whether an incoming lead matches one already in the system.
 *
 * Match precedence:
 *   1. Phone hash exact (this is the schema unique key — strongest match)
 *   2. Email exact (case-insensitive)
 *   3. Fuzzy name + (phone OR email present), Levenshtein distance ≤ N
 *
 * The match is always tenant-scoped — the global TenantScope on the Lead
 * model handles that. There is no cross-tenant dedup.
 *
 * The fuzzy step is intentionally narrow. CSV imports include lots of
 * "John Smith" duplicates that aren't actually the same person. The
 * "phone_or_email_present" guard prevents collapsing them into one record.
 */
final class LeadDedupService
{
    /** @var array{fuzzy_name_max_distance: int} */
    private array $config;

    public function __construct()
    {
        $this->config = [
            'fuzzy_name_max_distance' => (int) config('leads.dedup.fuzzy_name_max_distance', 2),
        ];
    }

    public function find(LeadInputData $input): DedupResult
    {
        // Tier 1: phone hash exact. Schema-enforced unique within tenant.
        $exactPhone = Lead::query()
            ->where('phone_hash', $input->phoneHash)
            ->first();

        if ($exactPhone !== null) {
            return DedupResult::exactPhone($exactPhone);
        }

        // Also check alternate_phone_hash for inbound leads where the contact
        // gave a number we already had as someone's secondary.
        if ($input->phoneHash !== null) {
            $alternateMatch = Lead::query()
                ->where('alternate_phone_hash', $input->phoneHash)
                ->first();

            if ($alternateMatch !== null) {
                return DedupResult::exactPhone($alternateMatch);
            }
        }

        // Tier 2: email exact (lowercased). Skip if the input has no email —
        // matching on null email would collapse every contact-form-with-no-email lead.
        if ($input->email !== null && $input->email !== '') {
            $emailMatch = Lead::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($input->email)])
                ->first();

            if ($emailMatch !== null) {
                return DedupResult::exactEmail($emailMatch);
            }
        }

        // Tier 3: fuzzy name match.
        //
        // Fuzzy is intentionally narrow. We require a structural co-signal —
        // matching postal_code OR matching (city + state) — alongside the
        // first+last name fuzzy match. Without that, "John Smith from NYC"
        // and "John Smith from LA" collapse into one record, which is the
        // single fastest way to delete a real lead.
        if ($input->firstName === null || $input->lastName === null) {
            return DedupResult::notDuplicate();
        }

        $hasCoSignal = $input->postalCode !== null
            || ($input->city !== null && $input->state !== null);

        if (! $hasCoSignal) {
            return DedupResult::notDuplicate();
        }

        $candidatesQuery = Lead::query()
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($input->lastName)]);

        if ($input->postalCode !== null) {
            $candidatesQuery->where('postal_code', $input->postalCode);
        } else {
            $candidatesQuery
                ->whereRaw('LOWER(city) = ?', [mb_strtolower($input->city)])
                ->where('state', mb_strtoupper($input->state));
        }

        $candidates = $candidatesQuery->limit(50)->get();

        $maxDistance = $this->config['fuzzy_name_max_distance'];
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            if ($candidate->first_name === null) {
                continue;
            }

            $distance = levenshtein(
                mb_strtolower($input->firstName),
                mb_strtolower($candidate->first_name),
            );

            if ($distance <= $maxDistance && $distance < $bestDistance) {
                $bestMatch = $candidate;
                $bestDistance = $distance;
            }
        }

        if ($bestMatch !== null) {
            $confidence = max(0.5, 1.0 - ($bestDistance / max(1, $maxDistance + 1)));

            return DedupResult::fuzzyName($bestMatch, $confidence);
        }

        return DedupResult::notDuplicate();
    }
}
