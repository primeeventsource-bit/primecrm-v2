<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\CallCenter\Domain\Models\AgentStatusRecord
 */
final class AgentStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'agent_id' => $this->agent_id,
            // The war room shows agent names, not UUIDs. relationLoaded()
            // keeps single-record endpoints (like /me) cheap when the
            // caller didn't ask for it.
            'agent_name' => $this->whenLoaded('agent', fn () => $this->agent?->name),
            'status' => $this->status?->value,
            'previous_status' => $this->previous_status,
            'current_call_id' => $this->current_call_id,
            'current_session_id' => $this->current_session_id,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
        ];
    }
}
