<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Actions;

use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Support\Enums\CallStatus;

/**
 * Hangs up a call from our side.
 *
 * Used by:
 *   - Agent clicking "End Call" in the dialer UI
 *   - Supervisor "kill bad call" action
 *   - Internal timeouts (call has been ringing for too long)
 *
 * The provider hangup is best-effort — if the call has already ended
 * on Twilio's side (race: webhook arrived first), the provider returns
 * a 4xx which we swallow. The local state is the source of truth.
 */
final class EndCallAction
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly CallStateService $callState,
    ) {}

    public function execute(Call $call, CallStatus $finalStatus = CallStatus::Completed): Call
    {
        if ($call->isTerminal()) {
            return $call;
        }

        if ($call->provider_call_sid !== null) {
            try {
                $this->provider->endCall($call->provider_call_sid);
            } catch (\Throwable $e) {
                // Twilio sometimes 404s if the call is already gone.
                logger()->info('Provider endCall failed (likely already ended)', [
                    'call_id' => $call->id,
                    'sid' => $call->provider_call_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->callState->markEnded($call, $finalStatus, payload: ['source' => 'application_request']);
    }
}
