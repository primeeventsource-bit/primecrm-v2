<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Application\Jobs;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Application\Actions\InitiateCallAction;
use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\Compliance\Application\Services\ComplianceGuardrailService;
use App\Modules\Dialer\Application\Services\LeadQueueService;
use App\Modules\Dialer\Domain\Events\DialSkipped;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Concerns\AppliesTenantContext;
use App\Support\Enums\AgentStatus;
use App\Support\Enums\DialerMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * THE structural property: the dialer cannot bypass compliance.
 *
 * Every outbound call goes through this job. The job's first I/O after
 * loading the lead is `$guardrail->mayDial(...)`. If the guardrail
 * rejects, the call never gets placed. There is no alternative code
 * path that initiates a Twilio call — InitiateCallAction is reachable
 * only through this job (or through manual click-to-call, which lives
 * in DialerSessionController and ALSO calls the guardrail before
 * delegating here).
 *
 * Picks one available agent at dial time. In predictive mode this is
 * normal — multiple DialLeadJobs fire concurrently per pacing tick, and
 * the one that connects gets handed an available agent. In progressive,
 * we expect 1:1 (one job per agent's "next call"). The selection of an
 * agent is racy; we let the first job to claim an agent win and let
 * the others abandon. (Abandons feed back into the FCC abandon-rate
 * limiter.)
 */
final class DialLeadJob implements ShouldQueue
{
    use AppliesTenantContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // do NOT retry; an unsuccessful dial is logged and dropped

    public function __construct(
        public readonly string $leadId,
        public readonly ?string $sessionId = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $agentIdHint = null, // progressive/manual modes
        public readonly string $dialerMode = 'predictive',
    ) {
        $this->captureTenantContext();
        $this->onQueue(config('queue.names.dialer'));
    }

    public function handle(
        ComplianceGuardrailService $guardrail,
        InitiateCallAction $initiate,
        AgentPresenceService $presence,
        LeadQueueService $queue,
        TenantContext $tenantContext,
    ): void {
        $this->applyTenantContext();

        $lead = Lead::query()->find($this->leadId);
        if ($lead === null) {
            return;
        }

        // ── GATE 1 ─────────────────────────────────────────────────────────
        // Compliance — the structural chokepoint. Anything that returns a
        // rejection here aborts the dial without placing a call.
        $decision = $guardrail->mayDial($lead, $this->dialerMode);

        if ($decision->isRejected()) {
            DialSkipped::dispatch(
                $lead,
                $decision->reason ?? 'compliance_rejection',
                $decision->rejectionCode?->value,
                $this->sessionId,
            );
            // Permanent rejections (DNC, terminal status, missing number)
            // are removed from the queue. Transient rejections (calling
            // window, frequency cap) are requeued with a score penalty so
            // they're picked up later when the gate has cleared.
            $this->disposeAfterRejection($lead, $decision->category(), $queue);

            return;
        }

        // ── GATE 2 ─────────────────────────────────────────────────────────
        // Agent selection. Predictive can dial without a pre-claimed agent
        // (the agent gets assigned when the call connects), but in
        // progressive/manual modes we need an agent up front.
        $agentId = $this->agentIdHint ?? $this->pickAvailableAgent($presence);

        if ($agentId === null && DialerMode::from($this->dialerMode) !== DialerMode::Predictive) {
            DialSkipped::dispatch($lead, 'no_agent_available', null, $this->sessionId);
            $queue->requeue($this->campaignId ?? '', $lead->id, scorePenalty: 5);

            return;
        }

        // For predictive, we still need an agent identity for Twilio's
        // <Dial><Client> TwiML to target. Pick one optimistically; if no
        // agent is available, abandon the call (counted in abandon_rate).
        if ($agentId === null) {
            $agentId = $this->pickAvailableAgent($presence);
            if ($agentId === null) {
                DialSkipped::dispatch($lead, 'predictive_no_agent_available', null, $this->sessionId);

                return;
            }
        }

        // ── GATE 3 ─────────────────────────────────────────────────────────
        // Place the call.
        try {
            $initiate->execute(
                lead: $lead,
                agentId: $agentId,
                dialSessionId: $this->sessionId,
                campaignId: $this->campaignId,
            );

            // Bump session counters atomically — these are read by the
            // supervisor war room. Best-effort; failures are logged but
            // don't fail the dial.
            if ($this->sessionId !== null) {
                DialSession::query()
                    ->where('id', $this->sessionId)
                    ->increment('calls_initiated');
            }

            // The lead is now off-queue. The next contact is gated by
            // frequency cap; a successful redial will go through normal
            // routing on a later tick.
        } catch (\Throwable $e) {
            DialSkipped::dispatch($lead, 'provider_error', null, $this->sessionId);
            logger()->error('DialLeadJob provider error', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pickAvailableAgent(AgentPresenceService $presence): ?string
    {
        $available = $presence->listAvailable();
        if (empty($available)) {
            return null;
        }

        // Random across available — the routing engine already handled
        // performance-weighting at LEAD ASSIGNMENT time. By the time we're
        // dialing, the lead is already targeted at an agent (or pool); the
        // dialer just needs *someone* who can take the call.
        return $available[array_rand($available)];
    }

    private function disposeAfterRejection(Lead $lead, ?string $category, LeadQueueService $queue): void
    {
        if ($this->campaignId === null) {
            return;
        }

        $transient = in_array($category, ['frequency', 'window'], true);

        if ($transient) {
            // Push back with a moderate score penalty; window/frequency
            // gates will clear with time, no need to remove permanently.
            $queue->requeue($this->campaignId, $lead->id, scorePenalty: 25);
        } else {
            // dnc, consent, lead_state — remove from active campaign queue.
            $queue->remove($this->campaignId, $lead->id);
        }
    }
}
