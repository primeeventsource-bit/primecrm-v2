<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Listeners;

use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\Compliance\Domain\Models\ContactAttempt;
use App\Support\Enums\AgentStatus;

/**
 * On call end:
 *   1. Backfill the contact_attempt's `outcome` so reporting can group
 *      "no answer" / "voicemail" / "connected" buckets cleanly.
 *   2. Roll the agent into Wrap-Up so the predictive dialer doesn't
 *      hand them another lead during the disposition window.
 */
final class UpdateContactOutcomeOnCallEnded
{
    public function __construct(
        private readonly AgentPresenceService $presence,
    ) {}

    public function handle(CallEnded $event): void
    {
        $call = $event->call;

        ContactAttempt::query()
            ->where('call_id', $call->id)
            ->update([
                'outcome' => $call->status?->value,
                'updated_at' => now(),
            ]);

        if ($call->agent_id !== null) {
            $this->presence->set(
                agentId: $call->agent_id,
                status: AgentStatus::WrapUp,
                callId: null,
                sessionId: $call->dial_session_id,
            );
        }
    }
}
