<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Services\GuestTokenService;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\PrimeConnectGuestToken;
use App\Support\Enums\RoomStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Staff-side controller for managing customer-facing guest tokens.
 *
 *   POST   /api/prime-connect/rooms/{id}/guest-tokens
 *   DELETE /api/prime-connect/rooms/{id}/guest-tokens/{tokenId}
 *
 * Public guest-side surface (token lookup + JWT mint) lives on
 * PrimeConnectGuestController, which is unauthenticated.
 */
final class PrimeConnectGuestTokenController extends Controller
{
    public function __construct(private readonly GuestTokenService $service) {}

    public function store(Request $request, string $roomId): JsonResponse
    {
        $request->validate([
            'display_name' => ['nullable', 'string', 'max:128'],
            // Cap TTL at 7 days. Anything longer would be a credential
            // sloppily left lying around; that's an account problem.
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
        ]);

        $room = Call::query()->video()->findOrFail($roomId);

        $user = $request->user();
        $isOwner = $user !== null && $user->id === $room->agent_id;
        $isSupervisor = $user?->role?->canSupervise() ?? false;
        if (! $isOwner && ! $isSupervisor) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Refuse if the room is already torn down — a guest link to a
        // dead room wastes the customer's time.
        if ($room->room_status === RoomStatus::Completed
            || $room->room_status === RoomStatus::Failed
        ) {
            return response()->json([
                'message' => 'Cannot create a guest link for a room that has ended.',
            ], 422);
        }

        $token = $this->service->issue(
            room: $room,
            createdByUserId: $user?->id,
            displayName: $request->input('display_name'),
            ttlMinutes: $request->integer('ttl_minutes') ?: null,
        );

        return response()->json([
            'id' => $token->id,
            'token' => $token->token,
            'expires_at' => $token->expires_at?->toIso8601String(),
            // The full URL the agent will share with the customer. We
            // compose it server-side so the client doesn't need to know
            // the app's base URL — it's already in config.
            'join_url' => url('/prime-connect/join/'.$token->token),
        ], 201);
    }

    public function destroy(Request $request, string $roomId, string $tokenId): JsonResponse
    {
        $room = Call::query()->video()->findOrFail($roomId);

        $user = $request->user();
        $isOwner = $user !== null && $user->id === $room->agent_id;
        $isSupervisor = $user?->role?->canSupervise() ?? false;
        if (! $isOwner && ! $isSupervisor) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $token = PrimeConnectGuestToken::query()
            ->where('id', $tokenId)
            ->where('call_id', $roomId)
            ->firstOrFail();

        $this->service->revoke($token);

        return response()->json(['ok' => true]);
    }
}
