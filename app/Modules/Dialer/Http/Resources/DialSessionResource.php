<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Dialer\Domain\Models\DialSession
 */
final class DialSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'campaign_id' => $this->campaign_id,
            'mode' => $this->mode?->value,
            'status' => $this->status,
            'leads_processed' => $this->leads_processed,
            'calls_initiated' => $this->calls_initiated,
            'calls_connected' => $this->calls_connected,
            'calls_abandoned' => $this->calls_abandoned,
            'total_talk_seconds' => $this->total_talk_seconds,
            'started_at' => $this->started_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
