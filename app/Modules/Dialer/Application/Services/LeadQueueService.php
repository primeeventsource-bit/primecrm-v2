<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Per-campaign lead queue, backed by a Redis sorted set.
 *
 * Score = lead.score (descending) so the highest-value leads pop first.
 * The queue is a working buffer — it's preloaded from Postgres in
 * batches (typically 100–500 leads per campaign) and refilled when it
 * drains below a threshold.
 *
 * Why Redis instead of just `SELECT … ORDER BY score DESC LIMIT N` per
 * tick? Because under predictive load each tick fires concurrent picks,
 * and Postgres locking on a "first available lead" pattern serializes
 * the dialer. Redis ZPOPMAX is atomic and microsecond-fast — it scales
 * to thousands of agents per tenant.
 *
 * Key shape:
 *   tenant:{tenant_id}:campaign:{campaign_id}:leadq → sorted set (lead_id → score)
 */
final class LeadQueueService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Top-up the queue from Postgres if it's below the refill threshold.
     * Returns the count of leads added to the queue.
     */
    public function refill(string $campaignId, int $targetDepth = 200): int
    {
        $current = $this->depthFor($campaignId);
        if ($current >= $targetDepth) {
            return 0;
        }

        $needed = $targetDepth - $current;
        $key = $this->key($campaignId);
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return 0;
        }

        // Fetch eligible leads. Pull more than needed to allow some to be
        // already-in-queue (we ZADD with NX semantics so duplicates no-op).
        $candidates = Lead::query()
            ->contactable()
            ->where(function ($q) use ($campaignId): void {
                // For now, all contactable leads in the tenant are fair game.
                // Once campaigns have lead_pool_sql / cohort filters wired up,
                // this is where we apply the campaign's targeting rule.
                $q->whereNotNull('id'); // no-op placeholder
            })
            ->whereNotIn('status', [
                LeadStatus::ClosedWon->value,
                LeadStatus::ClosedLost->value,
                LeadStatus::Dnc->value,
                LeadStatus::DoNotContact->value,
            ])
            ->orderByDesc('score')
            ->orderBy('last_contacted_at') // null first → never-contacted first
            ->limit($needed * 2)
            ->get(['id', 'score']);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $payload = [];
        foreach ($candidates as $lead) {
            $payload[$lead->id] = (float) $lead->score;
        }

        // ZADD with NX flag: only add new members; existing members keep their score.
        $args = ['NX'];
        foreach ($payload as $member => $score) {
            $args[] = $score;
            $args[] = $member;
        }

        $added = (int) Redis::connection('dialer')->executeRaw(array_merge(['ZADD', $key], $args));

        // Set a long TTL so abandoned campaigns don't leak Redis memory.
        Redis::connection('dialer')->expire($key, 86_400);

        return $added;
    }

    /**
     * Pop the highest-scoring lead (atomic). Returns the lead_id, or null
     * if the queue is empty.
     */
    public function pop(string $campaignId): ?string
    {
        $key = $this->key($campaignId);
        $result = Redis::connection('dialer')->zpopmax($key, 1);

        if (empty($result)) {
            return null;
        }

        // ZPOPMAX returns either a flat [member, score] pair or an
        // associative array depending on the underlying client. Handle both.
        if (is_array($result) && array_is_list($result) && count($result) >= 1) {
            return (string) $result[0];
        }
        if (is_array($result)) {
            return (string) array_key_first($result);
        }

        return (string) $result;
    }

    /**
     * Pop up to N leads in one round-trip.
     *
     * @return list<string>
     */
    public function popMany(string $campaignId, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $this->pop($campaignId);
            if ($id === null) {
                break;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    public function depthFor(string $campaignId): int
    {
        return (int) Redis::connection('dialer')->zcard($this->key($campaignId));
    }

    /**
     * Push a lead back onto the queue with optional delay-as-score-penalty.
     * Used when DialLeadJob refuses (e.g. guardrail temporarily rejected
     * the number for being outside the calling window — try again later).
     */
    public function requeue(string $campaignId, string $leadId, float $scorePenalty = 0): void
    {
        $key = $this->key($campaignId);
        $lead = Lead::query()->find($leadId);
        if ($lead === null) {
            return;
        }

        $score = max(0.0, (float) $lead->score - $scorePenalty);
        Redis::connection('dialer')->zadd($key, $score, $leadId);
    }

    /**
     * Remove a lead permanently (e.g. it was just dialed, or it failed
     * compliance and shouldn't be retried).
     */
    public function remove(string $campaignId, string $leadId): void
    {
        Redis::connection('dialer')->zrem($this->key($campaignId), $leadId);
    }

    /**
     * Wipe the queue — used when a campaign is paused or the cohort changes.
     */
    public function flush(string $campaignId): void
    {
        Redis::connection('dialer')->del($this->key($campaignId));
    }

    private function key(string $campaignId): string
    {
        $tenantId = $this->tenantContext->id() ?? 'global';

        return "tenant:{$tenantId}:campaign:{$campaignId}:leadq";
    }
}
