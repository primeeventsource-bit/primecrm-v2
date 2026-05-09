<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Actions\EndCallAction;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Http\Resources\CallResource;
use App\Support\Enums\CallStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Supervisor war-room actions over a live call.
 *
 *   - whisper: supervisor talks to the agent only (customer can't hear)
 *   - barge:   supervisor joins as a third party, both parties hear
 *   - kill:    end a runaway call immediately
 *
 * Whisper/barge work by adding the supervisor to the existing Twilio
 * conference associated with the call. We don't fan out the conference
 * setup here — that lives in TwiML the dialer issues at call answer
 * (Response 5 wires the actual softphone interactions). For now, kill
 * is the implemented action; whisper/barge endpoints exist but emit
 * "not_yet_implemented" so the API contract is stable.
 */
final class SupervisorCallController extends Controller
{
    public function __construct(private readonly EndCallAction $endCall) {}

    public function kill(Request $request, string $callId): CallResource
    {
        $this->authorizeSupervisor($request);

        $call = Call::query()->findOrFail($callId);

        return new CallResource($this->endCall->execute($call, CallStatus::Canceled));
    }

    public function whisper(Request $request, string $callId): JsonResponse
    {
        $this->authorizeSupervisor($request);

        // Phase: stable contract; the actual conference modification
        // happens once the dialer's TwiML wires conference IDs (Response 5).
        return response()->json([
            'call_id' => $callId,
            'action' => 'whisper',
            'status' => 'not_yet_implemented',
        ], 501);
    }

    public function barge(Request $request, string $callId): JsonResponse
    {
        $this->authorizeSupervisor($request);

        return response()->json([
            'call_id' => $callId,
            'action' => 'barge',
            'status' => 'not_yet_implemented',
        ], 501);
    }

    private function authorizeSupervisor(Request $request): void
    {
        if (! $request->user()->role->canSupervise()) {
            abort(403, 'Supervisor role required.');
        }
    }
}
