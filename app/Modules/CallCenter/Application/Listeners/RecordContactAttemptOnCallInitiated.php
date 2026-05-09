<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Listeners;

use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\Compliance\Application\Actions\RecordContactAttemptAction;
use App\Modules\Compliance\Domain\Models\ContactAttempt;

/**
 * Logs a contact_attempts row the instant a call goes "initiated".
 *
 * Critical for TCPA: the daily cap counts attempts, not connections.
 * If we waited until "answered" to log, a misbehaving dialer could
 * blow past the cap with thousands of unanswered ringings before any
 * row was written. Recording at "initiated" closes that hole.
 */
final class RecordContactAttemptOnCallInitiated
{
    public function __construct(
        private readonly RecordContactAttemptAction $record,
    ) {}

    public function handle(CallInitiated $event): void
    {
        $call = $event->call;

        $this->record->execute(
            phoneHash: hash('sha256', $call->to_number),
            attemptType: ContactAttempt::ATTEMPT_OUTBOUND_CALL,
            leadId: $call->lead_id,
            agentId: $call->agent_id,
            callId: $call->id,
            outcome: null, // will be updated when call ends
        );
    }
}
