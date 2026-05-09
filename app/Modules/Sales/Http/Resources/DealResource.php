<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Sales\Domain\Models\Deal
 */
final class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'agent_id' => $this->agent_id,
            'fronter_id' => $this->fronter_id,
            'additional_closer_ids' => $this->additional_closer_ids,
            'stage' => $this->stage?->value,
            'previous_stage' => $this->previous_stage,
            'lost_reason' => $this->lost_reason,
            'total_value' => $this->total_value,
            'snr_amount' => $this->snr_amount,
            'vd_amount' => $this->vd_amount,
            'payable_amount' => $this->payable_amount,
            'currency' => $this->currency,
            'booking_id' => $this->booking_id,
            'contract_id' => $this->contract_id,
            'pitch_data' => $this->pitch_data,
            'notes' => $this->notes,
            'expected_close_at' => $this->expected_close_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
