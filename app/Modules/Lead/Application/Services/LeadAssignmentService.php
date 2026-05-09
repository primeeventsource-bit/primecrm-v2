<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Lead\Domain\Events\LeadAssigned;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Lead\Infrastructure\Repositories\AgentPerformanceRepository;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\LeadPriority;
use App\Support\Enums\UserRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a lead to an agent.
 *
 * Modes (configurable per tenant via tenants.settings.leads.assignment.mode,
 * with the global default in config/leads.php):
 *
 *   round_robin — cycles eligible agents using a Redis-backed counter
 *                 keyed per tenant. Simple, fair, ignores performance.
 *
 *   performance — ranks eligible agents by AgentScore over a 30-day window
 *                 and weighted-randomly picks from the top N. The weighting
 *                 means a top performer is more likely to win, but the
 *                 #2-#5 still get leads — preventing burnout and starving.
 *
 *   skill_based — filters by required skills first, then performance-weighted.
 *                 Required skills come from the lead's source mapping in
 *                 config/leads.php (or future per-campaign config).
 *
 * Hot leads (priority=hot) bypass the pool and go to the single top performer
 * — they're worth too much to gamble on.
 *
 * Every assignment is audited with the decision rationale (mode used,
 * pool considered, the winning agent's score).
 */
final class LeadAssignmentService
{
    public function __construct(
        private readonly AgentPerformanceRepository $performance,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Pick and assign an agent for the lead.
     * Returns the assigned User, or null if no eligible agent could be found.
     */
    public function assign(Lead $lead, ?string $modeOverride = null): ?User
    {
        $mode = $modeOverride ?? config('leads.assignment.default_mode', 'performance');

        // Hot leads short-circuit to the single top performer if configured to.
        if ($lead->priority === LeadPriority::Hot && config('leads.assignment.hot_lead_skip_pool', true)) {
            $top = $this->topPerformer();

            if ($top !== null) {
                return $this->commit($lead, $top, 'hot_lead_skip_pool', ['original_mode' => $mode]);
            }
        }

        $eligible = $this->eligibleAgents();

        if ($eligible->isEmpty()) {
            $this->audit->record(
                action: 'lead.assignment_failed',
                entityType: 'lead',
                entityId: $lead->id,
                context: ['reason' => 'no_eligible_agents', 'mode' => $mode],
            );

            return null;
        }

        $chosen = match ($mode) {
            'round_robin' => $this->pickRoundRobin($eligible->all()),
            'skill_based' => $this->pickSkillBased($lead, $eligible->all()),
            default => $this->pickPerformanceWeighted($eligible->all()),
        };

        if ($chosen === null) {
            $this->audit->record(
                action: 'lead.assignment_failed',
                entityType: 'lead',
                entityId: $lead->id,
                context: ['reason' => 'no_match', 'mode' => $mode],
            );

            return null;
        }

        return $this->commit($lead, $chosen, $mode);
    }

    /**
     * Direct assignment by a supervisor (overrides routing logic).
     */
    public function reassign(Lead $lead, string $toAgentId, ?string $reason = 'manual_reassignment'): ?User
    {
        $agent = User::query()->find($toAgentId);

        if ($agent === null || ! $agent->role->canTakeCalls()) {
            return null;
        }

        return $this->commit($lead, $agent, $reason ?? 'manual_reassignment');
    }

    /* ----------------------------------------------------------------------
     | Routing primitives
     | ---------------------------------------------------------------------- */

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function eligibleAgents()
    {
        $eligibleRoles = (array) config('leads.assignment.eligible_roles', ['agent', 'fronter', 'closer']);
        $maxOpen = (int) config('leads.assignment.max_open_leads_per_agent', 200);

        // Agents with too many open leads sit out. We use a subquery rather
        // than a join so users with no leads don't get filtered out.
        $tenantId = $this->tenantContext->id();

        $overloaded = DB::table('leads')
            ->select('assigned_agent_id', DB::raw('COUNT(*) AS open_count'))
            ->where('tenant_id', $tenantId)
            ->whereNotNull('assigned_agent_id')
            ->whereNotIn('status', ['closed_won', 'closed_lost', 'dnc', 'do_not_contact', 'bad_number'])
            ->groupBy('assigned_agent_id')
            ->having('open_count', '>=', $maxOpen)
            ->pluck('assigned_agent_id')
            ->all();

        // SoftDeletes already filters deleted_at IS NULL via the User model.
        return User::query()
            ->whereIn('role', $eligibleRoles)
            ->when(! empty($overloaded), fn ($q) => $q->whereNotIn('id', $overloaded))
            ->get();
    }

    /**
     * Round-robin pick using a tenant-scoped Redis counter. Distribution is
     * uniform across the eligible set; ordering is by agent UUID so it's
     * deterministic for tests but rotates fairly across runs.
     *
     * @param  list<User>  $eligible
     */
    private function pickRoundRobin(array $eligible): ?User
    {
        if (empty($eligible)) {
            return null;
        }

        usort($eligible, fn (User $a, User $b) => strcmp($a->id, $b->id));

        $tenantId = $this->tenantContext->id();
        $key = "lead_rr:{$tenantId}";
        $next = (int) (Cache::increment($key) ?? 1);

        // Cache::increment doesn't auto-expire on first set with all stores —
        // re-set with a TTL when we wrap to keep the counter bounded.
        if ($next % 10_000 === 0) {
            Cache::put($key, $next, now()->addDays(7));
        }

        return $eligible[($next - 1) % count($eligible)];
    }

    /**
     * Performance-weighted random selection from the top-N.
     *
     * @param  list<User>  $eligible
     */
    private function pickPerformanceWeighted(array $eligible): ?User
    {
        if (empty($eligible)) {
            return null;
        }

        $weights = (array) config('leads.assignment.score_weights', [
            'conversion_rate' => 0.4,
            'revenue' => 0.3,
            'call_speed' => 0.2,
            'qa_score' => 0.1,
        ]);

        // Score each agent
        $scored = [];
        foreach ($eligible as $agent) {
            $m = $this->performance->metricsFor($agent->id);

            $score = $m['conversion_rate'] * (float) $weights['conversion_rate']
                + $m['revenue_normalized'] * (float) $weights['revenue']
                + $m['call_speed_normalized'] * (float) $weights['call_speed']
                + $m['qa_score'] * (float) $weights['qa_score'];

            // Floor: every eligible agent has *some* probability so cold-start
            // (no historical data) doesn't deadlock routing. The floor is small
            // enough that it doesn't dilute strong differentials.
            $scored[] = [
                'agent' => $agent,
                'score' => max(0.05, $score),
            ];
        }

        // Top N
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topN = (int) config('leads.assignment.performance_top_n', 5);
        $pool = array_slice($scored, 0, max(1, $topN));

        // Weighted random selection
        $total = array_sum(array_column($pool, 'score'));
        $r = mt_rand() / mt_getrandmax() * $total;

        $running = 0.0;
        foreach ($pool as $entry) {
            $running += $entry['score'];
            if ($r <= $running) {
                return $entry['agent'];
            }
        }

        // Float arithmetic edge case — fall back to the highest scorer
        return $pool[0]['agent'];
    }

    /**
     * Skill-based: filter by required skills, then performance-weighted.
     *
     * Skills come from per-source mapping in config (e.g. inbound_call leads
     * may require "english" and "closer_certified"). If a lead has no required
     * skills, it falls back to performance-weighted on the full eligible pool.
     *
     * @param  list<User>  $eligible
     */
    private function pickSkillBased(Lead $lead, array $eligible): ?User
    {
        $required = $this->requiredSkillsFor($lead);

        if (empty($required)) {
            return $this->pickPerformanceWeighted($eligible);
        }

        $matching = array_values(array_filter($eligible, function (User $agent) use ($required): bool {
            $skills = is_array($agent->skills) ? $agent->skills : [];

            foreach ($required as $skill) {
                if (! in_array($skill, $skills, true)) {
                    return false;
                }
            }

            return true;
        }));

        if (empty($matching)) {
            // Fall back rather than fail — but record it so ops can see when
            // skill requirements are too tight for the current roster.
            $this->audit->record(
                action: 'lead.assignment_skill_fallback',
                entityType: 'lead',
                entityId: $lead->id,
                context: ['required_skills' => $required],
            );

            return $this->pickPerformanceWeighted($eligible);
        }

        return $this->pickPerformanceWeighted($matching);
    }

    /**
     * @return list<string>
     */
    private function requiredSkillsFor(Lead $lead): array
    {
        $map = (array) config('leads.assignment.source_skill_map', []);

        return (array) ($map[$lead->source] ?? []);
    }

    private function topPerformer(): ?User
    {
        $eligible = $this->eligibleAgents()->all();

        if (empty($eligible)) {
            return null;
        }

        $top = null;
        $topScore = -1.0;

        foreach ($eligible as $agent) {
            $m = $this->performance->metricsFor($agent->id);
            $score = $m['conversion_rate'] * 0.4
                + $m['revenue_normalized'] * 0.3
                + $m['call_speed_normalized'] * 0.2
                + $m['qa_score'] * 0.1;

            if ($score > $topScore) {
                $top = $agent;
                $topScore = $score;
            }
        }

        return $top;
    }

    private function commit(Lead $lead, User $agent, string $reason, array $extraContext = []): User
    {
        $previous = $lead->assigned_agent_id;

        DB::transaction(function () use ($lead, $agent): void {
            $lead->update([
                'assigned_agent_id' => $agent->id,
                'assigned_at' => now(),
            ]);
        });

        $this->audit->record(
            action: 'lead.assigned',
            entityType: 'lead',
            entityId: $lead->id,
            changes: [
                'assigned_agent_id' => ['from' => $previous, 'to' => $agent->id],
            ],
            context: array_merge(['reason' => $reason], $extraContext),
        );

        LeadAssigned::dispatch($lead->fresh(), $agent->id, $previous, $reason);

        return $agent;
    }
}
