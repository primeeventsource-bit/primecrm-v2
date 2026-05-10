<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Services\TwilioAccessTokenService;
use App\Modules\CallCenter\Http\Requests\MintAccessTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * POST /api/prime-connect/access-token
 *
 * Hands the browser a Twilio Video JWT scoped to one room (when known).
 * The lobby calls this immediately before joining; the in-call view
 * re-mints when the existing token is within ~2 minutes of expiry.
 *
 * The role authorization gate lives in MintAccessTokenRequest::authorize
 * — supervisors can mint supervisor_* tokens, agents cannot.
 */
final class PrimeConnectAccessTokenController extends Controller
{
    public function __construct(private readonly TwilioAccessTokenService $tokens) {}

    public function store(MintAccessTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        // Identity: "{role}:{userId}". Twilio echoes this on every webhook
        // and frontend participant event, so audio routing decisions can
        // read the role straight off the wire (RoomParticipantRole::fromIdentity).
        $identity = sprintf('%s:%s', $validated['role'], $user->id);

        $token = $this->tokens->mint(
            identity: $identity,
            roomName: $validated['room_name'] ?? null,
            ttlMinutes: $validated['ttl_minutes'] ?? null,
        );

        return response()->json([
            'token' => $token->jwt,
            'identity' => $token->identity,
            'expires_at' => $token->expiresAt->format('c'),
        ]);
    }
}
