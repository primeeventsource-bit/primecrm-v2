<?php

declare(strict_types=1);

namespace App\Core\Shared\Broadcasting;

use App\Modules\CallCenter\Domain\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Wire-format event for Prime Connect lobby live updates.
 *
 * Kept distinct from CallEventBroadcast (voice) because the payload
 * shapes diverge enough that a discriminated-union on the frontend
 * would buy us nothing — the lobby never wants to render a voice call,
 * and the dialer never wants to render a video room.
 *
 * Channels:
 *   - tenant.{tenantId}.supervisor — lobby's "active sessions" list
 *     refreshes for every supervisor / floor manager regardless of
 *     which agent is on the room.
 *   - tenant.{tenantId}.agent.{creatorId} — the agent who created or
 *     joined the room sees their own row update without waiting on the
 *     supervisor channel.
 *
 * Event names follow `prime_connect.room.<verb>` (created, ended) so
 * the Vue Echo client can attach handlers per-verb without parsing the
 * payload.
 */
final class VideoRoomBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $agentId,
        public readonly string $eventName, // prime_connect.room.created | .ended
        public readonly array $payload,
    ) {}

    public static function fromCall(Call $call, string $eventName): self
    {
        return new self(
            tenantId: $call->tenant_id,
            agentId: $call->agent_id,
            eventName: $eventName,
            payload: [
                'id' => $call->id,
                'twilio_room_sid' => $call->twilio_room_sid,
                'room_name' => $call->room_name,
                'room_status' => $call->room_status?->value,
                'medium' => $call->medium?->value,
                'agent_id' => $call->agent_id,
                'lead_id' => $call->lead_id,
                'scheduled_for' => $call->scheduled_for?->toIso8601String(),
                'created_at' => $call->created_at?->toIso8601String(),
                'ended_at' => $call->ended_at?->toIso8601String(),
            ],
        );
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel(BroadcastChannel::supervisor($this->tenantId))];

        if ($this->agentId !== null) {
            $channels[] = new PrivateChannel(BroadcastChannel::agent($this->tenantId, $this->agentId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
