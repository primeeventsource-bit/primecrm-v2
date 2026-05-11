<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Services\GuestTokenService;
use App\Modules\CallCenter\Application\Services\TwilioAccessTokenService;
use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Public (unauthenticated) endpoints for the customer guest invite flow.
 *
 *   GET  /api/prime-connect/guest/{token}                 — fetch room info
 *   POST /api/prime-connect/guest/{token}/access-token    — mint Twilio JWT
 *
 * Both endpoints validate the token through GuestTokenService::resolve,
 * which bypasses the global tenant scope long enough to find the row
 * and then sets TenantContext from the row. Every subsequent query in
 * the request runs tenant-scoped, just like an authenticated request.
 *
 * Failure modes (expired / revoked / nonexistent) all collapse into a
 * single 404 so the public surface doesn't leak which case it is.
 *
 * No CSRF — Sanctum's CSRF guard only applies to stateful (session)
 * requests; these are public stateless API calls.
 */
final class PrimeConnectGuestController extends Controller
{
    public function __construct(
        private readonly GuestTokenService $tokens,
        private readonly TwilioAccessTokenService $access,
    ) {}

    /**
     * Returns just enough info for the guest page to render its lobby:
     *   - room_name (Twilio room name — the guest connects with this)
     *   - room_status
     *   - display_name (the agent-provided label, "Hi Maria!")
     *
     * Deliberately minimal — no agent identity, no lead pii, no
     * recording urls. The customer doesn't need that to join.
     */
    public function show(string $token): JsonResponse
    {
        $row = $this->tokens->resolve($token);
        if ($row === null) {
            return response()->json(['message' => 'Invalid or expired link.'], 404);
        }

        // Tenant is now set by resolve(); this query is properly scoped.
        $room = Call::query()->video()->find($row->call_id);
        if ($room === null) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        return response()->json([
            'room_name' => $room->room_name,
            'room_status' => $room->room_status?->value,
            'display_name' => $row->display_name,
            'expires_at' => $row->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Mint a Twilio Video JWT for the guest. Identity is "guest:{tokenId}"
     * so the staff side can tell who's joining without exposing any
     * customer pii in the Twilio webhook stream.
     *
     * The room name on the grant is pinned to the room the token was
     * issued for — a leaked guest token can ONLY join that specific
     * room, never anything else in the tenant.
     */
    public function accessToken(string $token): JsonResponse
    {
        $row = $this->tokens->resolve($token);
        if ($row === null) {
            return response()->json(['message' => 'Invalid or expired link.'], 404);
        }

        $room = Call::query()->video()->find($row->call_id);
        if ($room === null || $room->room_name === null) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        // "guest:{tokenRowId}" — short, opaque, audit-traceable. The
        // staff side maps this back to the token row to learn who the
        // agent invited.
        $identity = sprintf('guest:%s', $row->id);

        $jwt = $this->access->mint(
            identity: $identity,
            roomName: $room->room_name,
        );

        // Audit-stamp first use; subsequent re-mints (refresh, reconnect)
        // are no-ops on this call.
        $this->tokens->markUsed($row);

        return response()->json([
            'token' => $jwt->jwt,
            'identity' => $jwt->identity,
            'expires_at' => $jwt->expiresAt->format('c'),
            'room_name' => $room->room_name,
        ]);
    }
}
