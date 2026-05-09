<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Booking\Domain\Models\InventoryHold
 */
final class InventoryHoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_availability_id' => $this->inventory_availability_id,
            'lead_id' => $this->lead_id,
            'deal_id' => $this->deal_id,
            'held_by_id' => $this->held_by_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'release_reason' => $this->release_reason,
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
