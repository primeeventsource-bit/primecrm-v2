<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Events\AgentStatusChanged;
use App\Modules\CallCenter\Domain\Models\AgentStatusRecord;
use App\Support\Enums\AgentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Authoritative agent presence — Postgres row + Redis hot cache.
 *
 * Why both: the predictive dialer's pacing tick needs to count
 * "agents available right now" cheaply. Hitting Postgres on every
 * tick on every campaign on every tenant scales poorly. Redis gives
 * us microsecond reads. Postgres gives us durability across Redis
 * restarts and a queryable history for analytics.
 *
 * Redis key shape:
 *   tenant:{tenant_id}:agent_presence  → Hash of agent_id → JSON({status, ts, call_id})
 *
 * Single-key-per-tenant is the right granularity: one HMGET fetches
 * all agents for one campaign-pacing tick. The key lives on the
 * dedicated `dialer` Redis logical DB (config/database.php → redis.dialer)
 * so cache invalidation traffic doesn't compete with it.
 */
final class AgentPresenceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Set an agent's status. Writes Postgres + Redis atomically (Postgres
     * first; Redis can drift backwards if Postgres commits and Redis
     * fails — which is the right order, since durable wins).
     */
    public function set(
        string $agentId,
        AgentStatus $status,
        ?string $callId = null,
        ?string $sessionId = null,
    ): AgentStatusRecord {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            throw new \LogicException('AgentPresenceService::set requires a resolved tenant context.');
        }

        $previousStatus = null;

        $record = DB::transaction(function () use ($agentId, $tenantId, $status, $callId, $sessionId, &$previousStatus): AgentStatusRecord {
            $existing = AgentStatusRecord::query()
                ->where('agent_id', $agentId)
                ->lockForUpdate()
                ->first();

            $previousStatus = $existing?->status?->value;

            return AgentStatusRecord::query()->updateOrCreate(
                ['agent_id' => $agentId],
                [
                    'tenant_id' => $tenantId,
                    'status' => $status->value,
                    'previous_status' => $previousStatus,
                    'current_call_id' => $callId,
                    'current_session_id' => $sessionId,
                    'status_changed_at' => now(),
                    'last_heartbeat_at' => now(),
                ],
            );
        });

        $this->writeRedis($tenantId, $agentId, $status, $callId);

        if ($previousStatus !== $status->value) {
            $this->audit->record(
                action: 'agent.status_changed',
                entityType: 'agent',
                entityId: $agentId,
                changes: ['status' => ['from' => $previousStatus, 'to' => $status->value]],
                context: ['call_id' => $callId, 'session_id' => $sessionId],
            );

            AgentStatusChanged::dispatch(
                $tenantId,
                $agentId,
                $previousStatus !== null ? AgentStatus::from($previousStatus) : AgentStatus::Offline,
                $status,
                $callId,
            );
        }

        return $record;
    }

    /**
     * Lightweight heartbeat — touches last_heartbeat_at without changing status.
     * Called by the agent UI every few seconds; cheap.
     *
     * Redis side is best-effort. The DB update IS the durable presence
     * record; the Redis mirror is for the supervisor war-room's
     * sub-second view. On Cloud envs without Redis configured (or when
     * the dialer connection is down) we skip the Redis half and let the
     * heartbeat succeed on DB alone. Previously this threw — every
     * 20-second heartbeat call from every open tab returned a 500,
     * filling the log and creating the appearance of page instability.
     */
    public function heartbeat(string $agentId): void
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return;
        }

        DB::table('agent_statuses')
            ->where('agent_id', $agentId)
            ->update(['last_heartbeat_at' => now()]);

        try {
            Redis::connection('dialer')->hset(
                $this->presenceKey($tenantId),
                $agentId.':heartbeat',
                now()->toIso8601String(),
            );
        } catch (\Throwable) {
            // Redis unavailable — DB update above is the source of truth.
            // Don't fail the request; supervisor war-room loses its
            // sub-second view but heartbeat semantics still hold.
        }
    }

    /**
     * Returns the list of agent IDs currently in `Available` status for
     * the active tenant. Reads from Redis only — this is the dialer's
     * hot path. If Redis returns nothing (e.g. cold start after restart),
     * falls back to Postgres and re-warms the cache.
     *
     * @return list<string>
     */
    public function listAvailable(): array
    {
        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return [];
        }

        $key = $this->presenceKey($tenantId);
        $raw = Redis::connection('dialer')->hgetall($key);

        if (empty($raw)) {
            return $this->rewarmFromPostgres($tenantId);
        }

        $available = [];
        foreach ($raw as $field => $value) {
            if (str_contains($field, ':')) {
                continue; // skip metadata sub-keys like agentId:heartbeat
            }
            $decoded = json_decode((string) $value, true);
            if (is_array($decoded) && ($decoded['status'] ?? null) === AgentStatus::Available->value) {
                $available[] = $field;
            }
        }

        return $available;
    }

    /**
     * @return list<string>
     */
    private function rewarmFromPostgres(string $tenantId): array
    {
        $rows = AgentStatusRecord::query()
            ->where('status', AgentStatus::Available->value)
            ->get(['agent_id', 'status', 'current_call_id', 'status_changed_at']);

        $available = [];
        $key = $this->presenceKey($tenantId);

        foreach ($rows as $row) {
            $this->writeRedis($tenantId, $row->agent_id, AgentStatus::Available, $row->current_call_id);
            $available[] = $row->agent_id;
        }

        return $available;
    }

    private function writeRedis(string $tenantId, string $agentId, AgentStatus $status, ?string $callId): void
    {
        Redis::connection('dialer')->hset(
            $this->presenceKey($tenantId),
            $agentId,
            json_encode([
                'status' => $status->value,
                'call_id' => $callId,
                'ts' => now()->toIso8601String(),
            ]),
        );
    }

    private function presenceKey(string $tenantId): string
    {
        return "tenant:{$tenantId}:agent_presence";
    }
}
