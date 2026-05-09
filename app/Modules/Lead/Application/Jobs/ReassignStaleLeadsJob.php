<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Jobs;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Support\Concerns\AppliesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sweeps every active tenant for leads idle past the configured stale window
 * and dispatches AssignLeadJob to reassign them.
 *
 * Scheduled in routes/console.php (or via app('schedule') in a provider)
 * to run every minute. The lookback is short — 10 minutes by default —
 * so an unanswered hot lead doesn't sit idle on an offline agent.
 *
 * This job runs OUTSIDE any tenant context (it's a system sweep). Each
 * dispatch of AssignLeadJob carries the right tenant via runAs().
 */
final class ReassignStaleLeadsJob implements ShouldQueue
{
    use AppliesTenantContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(config('queue.names.lead_assignment'));
    }

    public function handle(\App\Core\Shared\TenantContext $tenantContext): void
    {
        $minutes = (int) config('leads.assignment.stale_assignment_minutes', 10);

        Tenant::query()
            ->where('status', 'active')
            ->chunkById(100, function ($tenants) use ($tenantContext, $minutes): void {
                foreach ($tenants as $tenant) {
                    $tenantContext->runAs($tenant->id, null, function () use ($minutes): void {
                        Lead::query()
                            ->staleAssignments($minutes)
                            ->select('id')
                            ->chunkById(500, function ($leads): void {
                                foreach ($leads as $lead) {
                                    AssignLeadJob::dispatch($lead->id, modeOverride: null);
                                }
                            });
                    });
                }
            });
    }
}
