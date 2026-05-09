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
 * Wire-format event broadcast on call state transitions.
 *
 * Fired by BroadcastDomainEvents listener whenever the domain emits a
 * CallInitiated / CallConnected / CallEnded. Goes to BOTH:
 *   - the assigned agent's channel (so the dialer UI updates instantly)
 *   - the tenant supervisor channel (so the war room sees it)
 *
 * Kept separate from the domain event so domain layer remains
 * broadcast-naive — a future SSE or other transport doesn't ripple back.
 */
final class CallEventBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $callId,
        public readonly ?string $agentId,
        public readonly string $eventName, // call.initiated, call.connected, call.ended
        public readonly array $payload,
    ) {}

    public static function fromCall(Call $call, string $eventName): self
    {
        return new self(
            tenantId: $call->tenant_id,
            callId: $call->id,
            agentId: $call->agent_id,
            eventName: $eventName,
            payload: [
                'id' => $call->id,
                'lead_id' => $call->lead_id,
                'agent_id' => $call->agent_id,
                'status' => $call->status?->value,
                'direction' => $call->direction?->value,
                'from_number' => $call->from_number,
                'to_number' => $call->to_number,
                'initiated_at' => $call->initiated_at?->toIso8601String(),
                'answered_at' => $call->answered_at?->toIso8601String(),
                'ended_at' => $call->ended_at?->toIso8601String(),
                'duration_seconds' => $call->duration_seconds,
                'disposition' => $call->disposition?->value,
            ],
        );
    }

    /**
     * @return list<PrivateChannel>
     */
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

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
