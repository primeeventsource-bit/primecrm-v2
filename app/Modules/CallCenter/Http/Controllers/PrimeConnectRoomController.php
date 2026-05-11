<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Actions\CreateInstantRoomAction;
use App\Modules\CallCenter\Application\Actions\EndRoomAction;
use App\Modules\CallCenter\Application\Services\CircuitOpenException;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Http\Requests\CreateInstantRoomRequest;
use App\Modules\CallCenter\Http\Resources\PrimeConnectRoomResource;
use App\Support\Enums\RoomStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Lobby + in-call backing for Prime Connect video rooms.
 *
 *   GET    /api/prime-connect/rooms       — list (filter by status, mine, lead)
 *   POST   /api/prime-connect/rooms       — create instant room
 *   GET    /api/prime-connect/rooms/{id}  — single room (for the in-call view)
 *   DELETE /api/prime-connect/rooms/{id}  — end room
 *
 * "Room" is a calls row with medium=video; the controller filters with
 * the Call::video() scope so this endpoint can NEVER return voice rows
 * by accident even if the underlying table is shared.
 */
final class PrimeConnectRoomController extends Controller
{
    public function __construct(
        private readonly CreateInstantRoomAction $createInstantRoom,
        private readonly EndRoomAction $endRoom,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'room_status' => ['nullable', 'string'],
            'mine' => ['nullable', 'boolean'],
            'lead_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // Eager-load lead + agent so PrimeConnectRoomResource can include
        // lead_name / agent_name without N+1 queries. We only need the
        // name columns; selecting the full row keeps the model hydrated
        // for the resource's whenLoaded() check.
        $query = Call::query()->video()->with(['participants', 'lead', 'agent']);

        if ($request->filled('room_status')) {
            $query->where('room_status', $request->string('room_status')->value());
        }
        if ($request->boolean('mine') && $request->user() !== null) {
            $query->where('agent_id', $request->user()->id);
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->string('lead_id')->value());
        }

        // Lobby always wants newest-first. Sorting on a column we already
        // index (the calls_tenant_medium_status_idx covers this prefix)
        // keeps it cheap.
        $page = $query->orderByDesc('created_at')
            ->paginate(min(200, (int) $request->integer('per_page', 25)));

        return PrimeConnectRoomResource::collection($page)->response();
    }

    public function show(string $id): PrimeConnectRoomResource
    {
        $call = Call::query()->video()
            ->with(['participants', 'lead', 'agent'])
            ->findOrFail($id);

        return new PrimeConnectRoomResource($call);
    }

    /**
     * Toggle the war-room flag on a room. The agent flips this from the
     * in-call UI when they want a supervisor's eyes on a call going
     * sideways without breaking flow to message anyone. The flag rides
     * inside lobby_metadata['war_room_flag'] (existing JSON column) so
     * we don't need a schema change for a boolean.
     *
     * Only the agent who owns the room or a supervisor can flip it.
     *   POST /api/prime-connect/rooms/{id}/flag { flagged: true|false }
     */
    public function flag(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'flagged' => ['required', 'boolean'],
        ]);

        $call = Call::query()->video()->findOrFail($id);

        $user = $request->user();
        $isOwner = $user !== null && $user->id === $call->agent_id;
        $isSupervisor = $user?->role?->canSupervise() ?? false;
        if (! $isOwner && ! $isSupervisor) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Merge into existing lobby_metadata rather than overwriting —
        // CreateInstantRoomAction may have stored other keys (device
        // ids, initial mute state, etc.) that we don't want to drop.
        $metadata = is_array($call->lobby_metadata) ? $call->lobby_metadata : [];
        $metadata['war_room_flag'] = $request->boolean('flagged');
        if ($request->boolean('flagged')) {
            $metadata['war_room_flagged_at'] = now()->toIso8601String();
            $metadata['war_room_flagged_by'] = $user?->id;
        } else {
            // Clearing the flag — leave the timestamps for the audit
            // trail; the boolean is the source of truth for live UI.
            unset($metadata['war_room_flag']);
        }
        $call->lobby_metadata = $metadata;
        $call->save();

        return response()->json([
            'ok' => true,
            'flagged' => $request->boolean('flagged'),
        ]);
    }

    public function store(CreateInstantRoomRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $call = $this->createInstantRoom->execute(
                agentUserId: $request->user()->id,
                leadId: $validated['lead_id'] ?? null,
                roomName: $validated['room_name'] ?? null,
                lobbyMetadata: $validated['lobby_metadata'] ?? [],
            );
        } catch (CircuitOpenException $e) {
            // Twilio is in a known-bad state; refuse fast so the lobby
            // can render its "voice-only mode" banner instead of spinning.
            return response()->json([
                'message' => 'Prime Connect is temporarily degraded. Voice-only mode is available.',
                'reason' => 'twilio_circuit_open',
            ], 503);
        }

        return (new PrimeConnectRoomResource($call->loadMissing('participants')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $call = Call::query()->video()->findOrFail($id);

        // Only the room creator (agent_id) or a supervisor can end a
        // room from this endpoint. Twilio room-ended webhooks land via
        // a separate path (TwilioWebhookController) and don't go through here.
        $user = $request->user();
        $isOwner = $user !== null && $user->id === $call->agent_id;
        $isSupervisor = $user?->role?->canSupervise() ?? false;
        if (! $isOwner && ! $isSupervisor) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($call->room_status === RoomStatus::Completed) {
            // Idempotent — already ended. Return the same 200 the caller
            // would have gotten on the first call.
            return response()->json(['ok' => true, 'already_ended' => true]);
        }

        $this->endRoom->execute($call, endedByUserId: $user->id);

        return response()->json(['ok' => true]);
    }
}
