<?php

declare(strict_types=1);

namespace App\Core\Shared\Broadcasting;

use App\Support\Enums\AgentStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AgentPresenceBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $agentId,
        public readonly AgentStatus $from,
        public readonly AgentStatus $to,
        public readonly ?string $callId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(BroadcastChannel::supervisor($this->tenantId)),
            new PrivateChannel(BroadcastChannel::agent($this->tenantId, $this->agentId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.presence_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agentId,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'call_id' => $this->callId,
            'at' => now()->toIso8601String(),
        ];
    }
}
