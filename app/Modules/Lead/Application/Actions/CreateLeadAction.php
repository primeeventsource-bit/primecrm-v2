<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Lead\Application\DTOs\DedupResult;
use App\Modules\Lead\Application\DTOs\LeadInputData;
use App\Modules\Lead\Application\Services\LeadDedupService;
use App\Modules\Lead\Application\Services\LeadScoringService;
use App\Modules\Lead\Domain\Events\LeadCreated;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadStatus;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for creating a lead.
 *
 * Always goes through dedup → score → persist. The action is idempotent
 * with respect to duplicates: if a duplicate is found, the existing lead's
 * mutable fields are merged (newer non-null values win) and that lead is
 * returned; the LeadCreated event is NOT re-fired.
 *
 * Why not let the schema unique index handle dedup with try/catch?
 * Because the unique index only catches phone_hash exact — it doesn't see
 * email matches or fuzzy name matches, both of which still indicate a
 * duplicate person. The dedup service handles all three cases and gives us
 * a chance to merge field-level data instead of either crashing or creating
 * a sneaky duplicate via different phone normalization.
 *
 * @see \App\Modules\Lead\Application\Services\LeadDedupService
 */
final class CreateLeadAction
{
    public function __construct(
        private readonly LeadDedupService $dedup,
        private readonly LeadScoringService $scoring,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{lead: Lead, was_duplicate: bool, dedup: DedupResult}
     */
    public function execute(LeadInputData $input): array
    {
        $dedupResult = $this->dedup->find($input);

        if ($dedupResult->isDuplicate && $dedupResult->existingLead !== null) {
            $merged = $this->mergeIntoExisting($dedupResult->existingLead, $input);

            $this->audit->record(
                action: 'lead.dedup_merged',
                entityType: 'lead',
                entityId: $merged->id,
                context: [
                    'match_type' => $dedupResult->matchType,
                    'confidence' => $dedupResult->confidence,
                    'source' => $input->source,
                ],
            );

            return [
                'lead' => $merged,
                'was_duplicate' => true,
                'dedup' => $dedupResult,
            ];
        }

        $lead = DB::transaction(function () use ($input): Lead {
            $attributes = $input->toAttributes();
            $attributes['status'] = LeadStatus::New->value;

            return Lead::query()->create($attributes);
        });

        // Compute score immediately rather than queueing — the scoring is pure
        // and cheap and the score is needed for routing decisions on the very
        // next request. The async ScoreLeadJob handles bulk re-scoring after
        // weight changes.
        $scored = $this->scoring->compute($lead);
        $lead->update(['score' => $scored['score']]);

        $this->audit->record(
            action: 'lead.created',
            entityType: 'lead',
            entityId: $lead->id,
            context: [
                'source' => $lead->source,
                'score' => $scored['score'],
                'score_breakdown' => $scored['breakdown'],
            ],
        );

        LeadCreated::dispatch($lead);

        return [
            'lead' => $lead,
            'was_duplicate' => false,
            'dedup' => $dedupResult,
        ];
    }

    /**
     * Merge logic: incoming non-null values overwrite null/empty existing
     * values, but never overwrite already-populated identity fields.
     * The phone field itself is NEVER changed (it's the dedup key).
     */
    private function mergeIntoExisting(Lead $existing, LeadInputData $input): Lead
    {
        $incoming = $input->toAttributes();

        // Phone is the dedup anchor; do not let merge change it.
        unset($incoming['phone'], $incoming['phone_hash']);

        $changes = [];

        $fillableFields = [
            'first_name', 'last_name', 'email', 'alternate_phone', 'alternate_phone_hash',
            'country', 'state', 'city', 'postal_code', 'timezone',
            'resort_interest', 'property_type', 'estimated_value',
            'source_metadata',
        ];

        foreach ($fillableFields as $field) {
            $newValue = $incoming[$field] ?? null;
            $currentValue = $existing->getAttribute($field);

            if ($newValue !== null && ($currentValue === null || $currentValue === '')) {
                $changes[$field] = $newValue;
            }
        }

        // source_campaign / source_medium / source_metadata are merged when newer info arrives
        // — they're tracking attribution and richer context is generally better.
        if ($input->sourceCampaign !== null) {
            $changes['source_campaign'] = $input->sourceCampaign;
        }
        if ($input->sourceMedium !== null) {
            $changes['source_medium'] = $input->sourceMedium;
        }
        if ($input->sourceMetadata !== null) {
            $existingMeta = is_array($existing->source_metadata) ? $existing->source_metadata : [];
            $changes['source_metadata'] = array_merge($existingMeta, $input->sourceMetadata);
        }

        if (! empty($changes)) {
            $existing->update($changes);
        }

        return $existing->fresh();
    }
}
