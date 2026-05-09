<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Jobs;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Modules\Dialer\Application\Services\LeadQueueService;
use App\Modules\Dialer\Application\Services\PacingEngine;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Support\Enums\DialerMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Periodic pacing tick. Runs every N seconds (config:
 * telephony.predictive.pacing_interval_seconds, default 30) per tenant
 * per active campaign.
 *
 * Per-tick work:
 *   1. For each active tenant
 *      For each active predictive/progressive campaign
 *        Read agents-available count from Redis presence
 *        Ask PacingEngine how many to dial
 *        Pop that many leads off the queue
 *        Dispatch DialLeadJob for each
 *        Refill the queue if it's running low
 *
 * Manual/preview modes don't tick — those flows are agent-initiated.
 *
 * The job is fire-and-forget per tenant. We DON'T loop tenants inside
 * one giant tick because a single slow tenant would stall every other
 * tenant. Instead the scheduler enqueues one tick job per tenant per
 * interval (TenantPacingTickSchedulerJob).
 */
final class PacingTickJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 25; // shorter than the pacing interval

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue(config('queue.names.dialer'));
    }

    public function handle(
        TenantContext $tenantContext,
        PacingEngine $pacing,
        LeadQueueService $queue,
        AgentPresenceService $presence,
    ): void {
        $tenantContext->set($this->tenantId);

        $campaigns = Campaign::query()
            ->active()
            ->whereIn('dialer_mode', [
                DialerMode::Predictive->value,
                DialerMode::Progressive->value,
            ])
            ->get();

        if ($campaigns->isEmpty()) {
            return;
        }

        $availableAgents = $presence->listAvailable();
        $totalAvailable = count($availableAgents);

        if ($totalAvailable === 0) {
            return;
        }

        // Allocate available agents across campaigns proportionally to
        // queue depth so a starved campaign with no leads doesn't hog
        // them. Simple even split is fine for v1.
        $perCampaignAgents = (int) ceil($totalAvailable / max(1, $campaigns->count()));

        foreach ($campaigns as $campaign) {
            // Refill queue if low
            $queue->refill($campaign->id);

            $decision = $pacing->decide($campaign, $perCampaignAgents);

            if ($decision->dialsToFire === 0) {
                continue;
            }

            $leadIds = $queue->popMany($campaign->id, $decision->dialsToFire);

            foreach ($leadIds as $leadId) {
                DialLeadJob::dispatch(
                    leadId: $leadId,
                    sessionId: null,
                    campaignId: $campaign->id,
                    agentIdHint: null,
                    dialerMode: $campaign->dialerMode()->value,
                );
            }

            logger()->debug('Pacing tick fired', [
                'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id,
                'decision' => $decision->toArray(),
                'leads_dispatched' => count($leadIds),
            ]);
        }
    }
}
