<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Modules\Dialer\Domain\ValueObjects\PacingDecision;
use Illuminate\Support\Facades\DB;

/**
 * Predictive dialer pacing math.
 *
 * Goal:
 *   Maximize agents-talking time. Minimize agents-idle. Stay under the
 *   FCC's 3% abandon-rate cap (rolling 30 days).
 *
 * The classic formula:
 *
 *     dial_rate = agents_available × (1 / connection_rate) × safety_factor
 *
 *   where:
 *     - agents_available = currently in `Available` status, NOT on a call,
 *       per Redis presence (cheap)
 *     - connection_rate  = recent fraction of dials that resulted in a
 *       human answer; rolling window measured from `calls` table
 *     - safety_factor    = adaptive multiplier that backs off when the
 *       observed abandon rate trends toward the FCC cap
 *
 * Adaptive feedback:
 *
 *   if abandon_rate > 0.7 × target_abandon_rate
 *       → safety_factor *= 0.85   (back off; we're trending unsafe)
 *   else if abandon_rate < 0.3 × target_abandon_rate
 *       → safety_factor *= 1.10   (push harder; we have headroom)
 *
 * Hard ceilings (config/telephony.predictive):
 *   - max_dials_per_agent (default 4) — never dial more than this per
 *     available agent, even if the math says otherwise. Catches
 *     pathological connection_rate spikes.
 *   - safety_factor clamped to [safety_factor_min, safety_factor_max]
 *
 * Cold start:
 *   When no recent data exists, connection_rate falls back to
 *   `min_connection_rate` (default 5%) so the engine doesn't divide by
 *   zero and conservatively dials at the floor.
 *
 * This service is pure-ish — it reads from Postgres + Redis but doesn't
 * mutate anything. The PacingTickJob is what fires DialLeadJobs based
 * on the decision.
 */
final class PacingEngine
{
    private const ROLLING_WINDOW_MINUTES = 5;
    private const ABANDON_WINDOW_DAYS = 30;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LeadQueueService $queue,
    ) {}

    public function decide(Campaign $campaign, int $agentsAvailable): PacingDecision
    {
        $config = config('telephony.predictive');

        $minConnRate = (float) $config['min_connection_rate'];
        $maxDialsPerAgent = (int) $config['max_dials_per_agent'];
        $sfMin = (float) $config['safety_factor_min'];
        $sfMax = (float) $config['safety_factor_max'];

        $connectionRate = max($this->observeConnectionRate($campaign), $minConnRate);
        $abandonRate = $this->observeAbandonRate($campaign);
        $safetyFactor = $this->adaptSafetyFactor(
            current: (float) $campaign->safety_factor,
            abandonRate: $abandonRate,
            target: (float) $campaign->target_abandon_rate,
            min: $sfMin,
            max: $sfMax,
        );

        $rawRate = $agentsAvailable * (1.0 / $connectionRate) * $safetyFactor;

        // Hard caps
        $perAgentCap = $agentsAvailable * $maxDialsPerAgent;
        $cappedByAgent = min($rawRate, (float) $perAgentCap);

        // Subtract dials already in flight — calls that are queued/initiated
        // but not yet ringing are unanswered "in-flight" attempts that still
        // consume our budget. Without this, we'd double-fire on every tick
        // until the in-flight ones finish.
        $inFlight = $this->countInFlight($campaign);

        $remainingBudget = max(0, (int) floor($cappedByAgent) - $inFlight);

        // Don't dial more than the campaign actually has waiting in the queue.
        $queueDepth = $this->queue->depthFor($campaign->id);
        $finalDials = min($remainingBudget, $queueDepth);

        $reason = match (true) {
            $agentsAvailable === 0 => 'no_agents_available',
            $abandonRate > (float) $campaign->target_abandon_rate => 'abandon_rate_at_cap',
            $queueDepth === 0 => 'lead_queue_empty',
            $finalDials === $perAgentCap => 'per_agent_cap',
            default => 'normal',
        };

        return new PacingDecision(
            dialsToFire: $finalDials,
            agentsAvailable: $agentsAvailable,
            agentsOnCall: $this->countAgentsOnCall($campaign),
            connectionRate: round($connectionRate, 4),
            abandonRate: round($abandonRate, 4),
            safetyFactor: round($safetyFactor, 3),
            rawRate: round($rawRate, 3),
            reason: $reason,
        );
    }

    /**
     * Observed connection rate over the recent rolling window.
     * "Connected" = call made it to in_progress (someone answered AND we
     * had an agent to give them to).
     */
    private function observeConnectionRate(Campaign $campaign): float
    {
        $tenantId = $this->tenantContext->id();
        $since = now()->subMinutes(self::ROLLING_WINDOW_MINUTES);

        $stats = DB::table('calls')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaign->id)
            ->where('initiated_at', '>=', $since)
            ->selectRaw('
                COUNT(*) AS attempted,
                COUNT(*) FILTER (WHERE answered_at IS NOT NULL) AS connected
            ')
            ->first();

        $attempted = (int) ($stats?->attempted ?? 0);
        $connected = (int) ($stats?->connected ?? 0);

        if ($attempted === 0) {
            return 0.0; // caller floors to min_connection_rate
        }

        return $connected / $attempted;
    }

    /**
     * Abandon rate over the FCC 30-day rolling window.
     * "Abandoned" = answered by human, but no agent was available to take
     * the call within ~2 seconds. We persist this via call.substatus
     * = 'abandoned' when we detect it (Response 5 wires the answering-
     * machine vs human + agent-available logic; for now we count
     * status = canceled with substatus = abandoned).
     */
    private function observeAbandonRate(Campaign $campaign): float
    {
        $tenantId = $this->tenantContext->id();
        $since = now()->subDays(self::ABANDON_WINDOW_DAYS);

        $stats = DB::table('calls')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaign->id)
            ->where('initiated_at', '>=', $since)
            ->whereNotNull('answered_at') // denominator: calls answered by humans
            ->selectRaw('
                COUNT(*) AS answered,
                COUNT(*) FILTER (WHERE substatus = ?) AS abandoned
            ', ['abandoned'])
            ->first();

        $answered = (int) ($stats?->answered ?? 0);
        $abandoned = (int) ($stats?->abandoned ?? 0);

        if ($answered === 0) {
            return 0.0;
        }

        return $abandoned / $answered;
    }

    /**
     * Calls already initiated against this campaign that haven't reached
     * a terminal status yet — these consume future budget.
     */
    private function countInFlight(Campaign $campaign): int
    {
        $tenantId = $this->tenantContext->id();

        return (int) DB::table('calls')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'initiated', 'ringing'])
            ->count();
    }

    private function countAgentsOnCall(Campaign $campaign): int
    {
        $tenantId = $this->tenantContext->id();

        return (int) DB::table('agent_statuses')
            ->where('tenant_id', $tenantId)
            ->where('status', 'on_call')
            ->count();
    }

    private function adaptSafetyFactor(
        float $current,
        float $abandonRate,
        float $target,
        float $min,
        float $max,
    ): float {
        $upperWarn = $target * 0.7;
        $lowerEdge = $target * 0.3;

        if ($abandonRate > $upperWarn) {
            $current *= 0.85;
        } elseif ($abandonRate < $lowerEdge) {
            $current *= 1.10;
        }

        return max($min, min($max, $current));
    }
}
