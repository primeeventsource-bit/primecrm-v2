<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Modules\Dialer\Domain\Events\DialSessionEnded;
use App\Modules\Dialer\Domain\Events\DialSessionStarted;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\AgentStatus;
use App\Support\Enums\DialerMode;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of a single agent's dial session.
 *
 *   start  → creates an active row; flips agent presence to Available;
 *            warms the campaign's lead queue if needed
 *   pause  → paused state; agent goes to OnBreak so the dialer skips them
 *   resume → back to active; presence to Available
 *   stop   → terminal; closes the session
 *
 * The dialer's pacing tick (PacingTickJob) reads ACTIVE sessions to
 * find available agents. A session in `paused` or `stopped` doesn't
 * count toward "agents_available" and won't have leads dispatched to it.
 */
final class DialerService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AgentPresenceService $presence,
        private readonly LeadQueueService $queue,
        private readonly AuditLogService $audit,
    ) {}

    public function start(User $agent, ?Campaign $campaign = null, ?DialerMode $modeOverride = null): DialSession
    {
        // Refuse if the agent already has an active session — duplicate
        // sessions confuse pacing math (the same agent counted twice).
        $existing = DialSession::query()
            ->forAgent($agent->id)
            ->whereIn('status', [DialSession::STATUS_ACTIVE, DialSession::STATUS_PAUSED])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $mode = $modeOverride
            ?? $campaign?->dialerMode()
            ?? DialerMode::Predictive;

        $session = DB::transaction(function () use ($agent, $campaign, $mode): DialSession {
            return DialSession::query()->create([
                'agent_id' => $agent->id,
                'campaign_id' => $campaign?->id,
                'mode' => $mode->value,
                'status' => DialSession::STATUS_ACTIVE,
                'started_at' => now(),
                'settings' => [],
            ]);
        });

        $this->presence->set($agent->id, AgentStatus::Available, sessionId: $session->id);

        if ($campaign !== null) {
            $this->queue->refill($campaign->id);
        }

        $this->audit->record(
            action: 'dialer.session_started',
            entityType: 'dial_session',
            entityId: $session->id,
            context: [
                'mode' => $mode->value,
                'campaign_id' => $campaign?->id,
                'agent_id' => $agent->id,
            ],
        );

        DialSessionStarted::dispatch($session);

        return $session;
    }

    public function pause(DialSession $session): DialSession
    {
        if (! $session->isActive()) {
            return $session;
        }

        $session->update([
            'status' => DialSession::STATUS_PAUSED,
            'paused_at' => now(),
        ]);

        $this->presence->set($session->agent_id, AgentStatus::OnBreak, sessionId: $session->id);

        return $session->fresh();
    }

    public function resume(DialSession $session): DialSession
    {
        if ($session->status !== DialSession::STATUS_PAUSED) {
            return $session;
        }

        $session->update([
            'status' => DialSession::STATUS_ACTIVE,
            'paused_at' => null,
        ]);

        $this->presence->set($session->agent_id, AgentStatus::Available, sessionId: $session->id);

        return $session->fresh();
    }

    public function stop(DialSession $session, string $reason = 'agent_request'): DialSession
    {
        if (in_array($session->status, [DialSession::STATUS_STOPPED, DialSession::STATUS_ENDED], true)) {
            return $session;
        }

        $session->update([
            'status' => DialSession::STATUS_ENDED,
            'ended_at' => now(),
        ]);

        $this->presence->set($session->agent_id, AgentStatus::Offline, sessionId: null);

        $this->audit->record(
            action: 'dialer.session_ended',
            entityType: 'dial_session',
            entityId: $session->id,
            context: ['reason' => $reason],
        );

        DialSessionEnded::dispatch($session->fresh(), $reason);

        return $session->fresh();
    }
}
