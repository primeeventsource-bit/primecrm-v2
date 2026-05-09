<?php

declare(strict_types=1);

namespace App\Modules\Lead\Infrastructure\Repositories;

use App\Core\Shared\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-agent performance metrics over a rolling window for use in
 * lead-routing decisions.
 *
 * Numbers are recomputed on demand and cached in Redis for a short TTL —
 * minutely staleness is fine because routing decisions don't need to be
 * re-explained on the same second they're made. Cache busting on each
 * deal/call event is overkill at our scale and would create cache stampedes.
 *
 * Returns normalized 0..1 components so the score formula is stable as
 * absolute numbers grow:
 *   - conversion_rate    — closed_won deals / contacted leads, last N days
 *   - revenue_normalized — agent's revenue / max revenue across pool
 *   - call_speed_normalized — 1 - (agent avg seconds-to-pickup / pool max)
 *   - qa_score           — placeholder until QA module ships (constant 0.7)
 *
 * The Sales/Commission/Call modules don't have models in this response yet,
 * so the SQL queries hit the underlying tables directly. Once those modules
 * land we'll route through their repositories.
 */
final class AgentPerformanceRepository
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Performance components for a single agent.
     *
     * @return array{conversion_rate: float, revenue_normalized: float, call_speed_normalized: float, qa_score: float}
     */
    public function metricsFor(string $agentId): array
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            // No tenant context = no metrics. Routing falls back to the floor.
            return $this->floor();
        }

        $cacheKey = "agent_perf:{$tenantId}:{$agentId}";
        $ttl = (int) config('leads.assignment.metrics_cache_ttl_seconds', 300);

        return Cache::remember($cacheKey, $ttl, fn () => $this->computeForAgent($tenantId, $agentId));
    }

    /**
     * Bulk fetch — single tenant query, then per-agent normalization.
     *
     * @param  list<string>  $agentIds
     * @return array<string, array{conversion_rate: float, revenue_normalized: float, call_speed_normalized: float, qa_score: float}>
     */
    public function metricsForMany(array $agentIds): array
    {
        $result = [];

        foreach ($agentIds as $id) {
            $result[$id] = $this->metricsFor($id);
        }

        return $result;
    }

    /**
     * @return array{conversion_rate: float, revenue_normalized: float, call_speed_normalized: float, qa_score: float}
     */
    private function computeForAgent(string $tenantId, string $agentId): array
    {
        $windowDays = (int) config('leads.assignment.metrics_window_days', 30);
        $since = now()->subDays($windowDays);

        // Lead conversion: contacted leads that hit closed_won attributable to this agent.
        // Both deals and leads tables have tenant_id; we anchor on the tenant for both.
        $leadStats = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('assigned_agent_id', $agentId)
            ->where('updated_at', '>=', $since)
            ->selectRaw('
                COUNT(*) FILTER (WHERE status IN (?, ?, ?, ?, ?)) AS contacted,
                COUNT(*) FILTER (WHERE status = ?) AS won
            ', [
                'contacted', 'qualified', 'pitch_presented', 'negotiating', 'closed_won',
                'closed_won',
            ])
            ->first();

        $contacted = (int) ($leadStats->contacted ?? 0);
        $won = (int) ($leadStats->won ?? 0);
        $conversionRate = $contacted > 0 ? min(1.0, $won / $contacted) : 0.0;

        // Revenue is normalized against the tenant's top performer in the window.
        // If there's no top performer (cold start), every agent gets 0 — and they're
        // all equal, which is what we want.
        $agentRevenue = (float) DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->where('stage', 'closed_won')
            ->where('updated_at', '>=', $since)
            ->sum('amount');

        $topRevenue = (float) DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->where('stage', 'closed_won')
            ->where('updated_at', '>=', $since)
            ->groupBy('agent_id')
            ->orderByRaw('SUM(amount) DESC')
            ->limit(1)
            ->value(DB::raw('SUM(amount)'));

        $revenueNormalized = $topRevenue > 0
            ? min(1.0, $agentRevenue / $topRevenue)
            : 0.0;

        // Call speed: average ring_seconds for connected calls. Lower = better.
        // Normalized so floor (slowest) = 0, top (fastest) = 1.
        $agentRingAvg = DB::table('calls')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->whereIn('status', ['completed', 'in_progress'])
            ->where('created_at', '>=', $since)
            ->avg('ring_seconds');

        $worstRingAvg = DB::table('calls')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'in_progress'])
            ->where('created_at', '>=', $since)
            ->groupBy('agent_id')
            ->orderByRaw('AVG(ring_seconds) DESC')
            ->limit(1)
            ->value(DB::raw('AVG(ring_seconds)'));

        $callSpeedNormalized = ($agentRingAvg !== null && $worstRingAvg !== null && (float) $worstRingAvg > 0)
            ? max(0.0, min(1.0, 1.0 - ((float) $agentRingAvg / (float) $worstRingAvg)))
            : 0.5; // unknowns get the middle, not zero

        return [
            'conversion_rate' => round($conversionRate, 4),
            'revenue_normalized' => round($revenueNormalized, 4),
            'call_speed_normalized' => round($callSpeedNormalized, 4),
            // QA module ships in Response 5. Until then a constant midpoint
            // means QA contribution is uniform and doesn't skew routing.
            'qa_score' => 0.7,
        ];
    }

    /**
     * @return array{conversion_rate: float, revenue_normalized: float, call_speed_normalized: float, qa_score: float}
     */
    private function floor(): array
    {
        return [
            'conversion_rate' => 0.0,
            'revenue_normalized' => 0.0,
            'call_speed_normalized' => 0.0,
            'qa_score' => 0.0,
        ];
    }

    public function bustCache(string $agentId): void
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId !== null) {
            Cache::forget("agent_perf:{$tenantId}:{$agentId}");
        }
    }
}
