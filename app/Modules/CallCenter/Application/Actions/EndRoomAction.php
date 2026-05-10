<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use App\Modules\CallCenter\Domain\Events\RoomEnded;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Support\Enums\CallStatus;
use App\Support\Enums\RoomStatus;
use Throwable;
use Twilio\Exceptions\RestException;

/**
 * Ends a Prime Connect video room from our side.
 *
 * Idempotent: if the room is already terminal, returns the existing
 * row unchanged. The Twilio call is best-effort — Twilio may have
 * already marked the room completed (room-ended webhook arrived first
 * via a different code path); 4xx is swallowed so the local state can
 * still be updated to terminal.
 *
 * Does NOT trigger the recording composition pipeline — that happens
 * later via the Twilio room-ended webhook (S4) → composition-available
 * webhook (S7) → ProcessCompletedRoomCompositionJob. We don't compose
 * eagerly because Twilio takes 10s-2min to finalize all per-participant
 * tracks after the room actually goes terminal on their side.
 */
final class EndRoomAction
{
    public function __construct(
        private readonly TwilioRoomService $rooms,
        private readonly AuditLogService $audit,
    ) {}

    public function execute(Call $call, ?string $endedByUserId = null): Call
    {
        if ($call->room_status === RoomStatus::Completed || $call->room_status === RoomStatus::Failed) {
            return $call;
        }

        if ($call->twilio_room_sid !== null) {
            try {
                $this->rooms->endRoom($call->twilio_room_sid);
            } catch (RestException $e) {
                // Twilio side already terminal; let the local state update proceed.
                if ($e->getStatusCode() < 400 || $e->getStatusCode() >= 500) {
                    throw $e; // 5xx genuinely unhealthy — bubble up
                }
            } catch (Throwable $e) {
                // Anything else (network, breaker open) bubbles — caller decides.
                throw $e;
            }
        }

        $call->update([
            'room_status' => RoomStatus::Completed->value,
            'status' => CallStatus::Completed->value,
            'ended_at' => now(),
        ]);

        $this->audit->record(
            action: 'prime_connect.room.ended',
            entityType: 'call',
            entityId: $call->id,
            context: [
                'twilio_room_sid' => $call->twilio_room_sid,
                'ended_by_user_id' => $endedByUserId,
            ],
        );

        RoomEnded::dispatch($call->fresh());

        return $call->fresh();
    }
}
