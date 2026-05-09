<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Jobs;

use App\Modules\Lead\Application\Services\LeadScoringService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Concerns\AppliesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes a single lead's score and persists it.
 *
 * Dispatched on bulk re-score after weight changes, on contact_attempts
 * increment, and from listeners reacting to deal-stage transitions.
 * Runs on the lead-scoring queue (Horizon supervisor: supervisor-leads).
 */
final class ScoreLeadJob implements ShouldQueue
{
    use AppliesTenantContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly string $leadId)
    {
        $this->captureTenantContext();
        $this->onQueue(config('queue.names.lead_scoring'));
    }

    public function handle(LeadScoringService $scoring): void
    {
        $this->applyTenantContext();

        $lead = Lead::query()->find($this->leadId);

        if ($lead === null) {
            return; // soft-deleted or different tenant — no-op
        }

        $scored = $scoring->compute($lead);

        if ((int) $lead->score !== $scored['score']) {
            $lead->update(['score' => $scored['score']]);
        }
    }
}
