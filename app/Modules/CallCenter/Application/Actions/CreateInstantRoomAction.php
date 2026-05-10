<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use App\Modules\CallCenter\Domain\Events\RoomCreated;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Support\Enums\CallDirection;
use App\Support\Enums\CallMedium;
use App\Support\Enums\CallStatus;
use App\Support\Enums\RoomStatus;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for "create a video room right now."
 *
 * Order matters:
 *   1. Insert the calls row (medium=video, status=in_progress, room_status=created)
 *      WITHOUT a Twilio room SID. Gives us a UUID for the unique room name.
 *   2. Call Twilio. Use the calls.id UUID as uniqueName so the round-trip
 *      from Twilio events back to our row is just a SID lookup.
 *   3. Patch the row with the returned SID + room_status=in_progress
 *      (Twilio rooms transition to in-progress immediately on create).
 *
 * If Twilio call fails (timeout, 5xx after retry, circuit open), the
 * transaction rolls back and the calls row never persists. Caller sees
 * the underlying exception (TwilioException or CircuitOpenException);
 * controller maps to a 503 with a "service degraded" body.
 *
 * Why not lazy-create on first join? Because the agent's UI needs a
 * real room SID to navigate to (/prime-connect/room/{sid}) before any
 * participant exists. Lazy is right for SCHEDULED rooms only — those
 * use ScheduleRoomAction (S3, separate DB-only path).
 */
final class CreateInstantRoomAction
{
    public function __construct(
        private readonly TwilioRoomService $rooms,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param array<string, mixed> $lobbyMetadata  invited identities, deal context, etc.
     */
    public function execute(
        string $agentUserId,
        ?string $leadId = null,
        ?string $roomName = null,
        array $lobbyMetadata = [],
    ): Call {
        return DB::transaction(function () use ($agentUserId, $leadId, $roomName, $lobbyMetadata): Call {
            // 1. Reserve the row so we have a UUID for the Twilio uniqueName.
            $call = Call::query()->create([
                'lead_id' => $leadId,
                'agent_id' => $agentUserId,
                // Voice surface fields we leave blank for video.
                'provider' => 'twilio',
                'from_number' => '',
                'to_number' => '',
                'direction' => CallDirection::Outbound->value,
                'status' => CallStatus::InProgress->value,
                'initiated_at' => now(),
                // Video-specific fields.
                'medium' => CallMedium::Video->value,
                'room_name' => $roomName ?? 'pc-instant',
                'room_status' => RoomStatus::Created->value,
                'lobby_metadata' => $lobbyMetadata,
            ]);

            // 2. Twilio side. uniqueName = our UUID — easiest correlation key
            // across the whole room lifecycle. Twilio rejects duplicate names,
            // so a retry that re-runs this action won't double-create.
            $twilioRoom = $this->rooms->createRoom(uniqueName: $call->id);

            // 3. Patch with what Twilio returned.
            $call->update([
                'twilio_room_sid' => $twilioRoom->sid,
                'room_status' => RoomStatus::InProgress->value,
            ]);

            $this->audit->record(
                action: 'prime_connect.room.created',
                entityType: 'call',
                entityId: $call->id,
                context: [
                    'twilio_room_sid' => $twilioRoom->sid,
                    'agent_id' => $agentUserId,
                    'lead_id' => $leadId,
                ],
            );

            RoomCreated::dispatch($call->fresh());

            return $call->fresh();
        });
    }
}
