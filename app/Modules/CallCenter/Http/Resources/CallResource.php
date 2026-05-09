<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\CallCenter\Domain\Models\Call
 */
final class CallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'agent_id' => $this->agent_id,
            'dial_session_id' => $this->dial_session_id,
            'campaign_id' => $this->campaign_id,
            'provider' => $this->provider,
            'provider_call_sid' => $this->provider_call_sid,
            'from_number' => $this->from_number,
            'to_number' => $this->to_number,
            'direction' => $this->direction?->value,
            'status' => $this->status?->value,
            'substatus' => $this->substatus,
            'disposition' => $this->disposition?->value,
            'disposition_notes' => $this->disposition_notes,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'ring_seconds' => $this->ring_seconds,
            'duration_seconds' => $this->duration_seconds,
            'wrap_up_seconds' => $this->wrap_up_seconds,
            'recording_status' => $this->recording_status,
            'recording_duration_seconds' => $this->recording_duration_seconds,
            'has_recording' => $this->recording_s3_path !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
