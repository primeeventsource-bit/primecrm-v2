<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Jobs;

use App\Modules\Lead\Application\Services\LeadAssignmentService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Concerns\AppliesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Assigns (or reassigns) a single lead.
 *
 * Dispatched by:
 *   - LeadCreated listener (initial assignment)
 *   - ReassignStaleLeadsJob (10-minute idle reassignment)
 *   - HTTP endpoint when supervisor manually triggers reroute
 *
 * Idempotent: safe to dispatch twice; the second run will see the lead
 * already assigned to a still-eligible agent and either no-op or rotate.
 */
final class AssignLeadJob implements ShouldQueue
{
    use AppliesTenantContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(
        public readonly string $leadId,
        public readonly ?string $modeOverride = null,
    ) {
        $this->captureTenantContext();
        $this->onQueue(config('queue.names.lead_assignment'));
    }

    public function handle(LeadAssignmentService $service): void
    {
        $this->applyTenantContext();

        $lead = Lead::query()->find($this->leadId);

        if ($lead === null || ! $lead->isContactable()) {
            return;
        }

        $service->assign($lead, $this->modeOverride);
    }
}
