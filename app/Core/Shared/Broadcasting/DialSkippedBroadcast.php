<?php

declare(strict_types=1);

namespace App\Core\Shared\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on the supervisor channel when the guardrail rejects a dial.
 *
 * The supervisor war room flashes these as alerts so ops can see, in real
 * time, when (e.g.) the calling window closes for the East Coast and a
 * surge of OutsideCallingWindow rejections starts.
 */
final class DialSkippedBroadcast implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $leadId,
        public readonly string $reason,
        public readonly ?string $rejectionCode,
        public readonly ?string $sessionId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(BroadcastChannel::supervisor($this->tenantId))];
    }

    public function broadcastAs(): string
    {
        return 'dialer.skipped';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'reason' => $this->reason,
            'rejection_code' => $this->rejectionCode,
            'session_id' => $this->sessionId,
            'at' => now()->toIso8601String(),
        ];
    }
}
