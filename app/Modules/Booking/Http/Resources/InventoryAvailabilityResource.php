<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Booking\Domain\Models\InventoryAvailability
 */
final class InventoryAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resort_id' => $this->resort_id,
            'inventory_unit_id' => $this->inventory_unit_id,
            'check_in_date' => $this->check_in_date?->toDateString(),
            'check_out_date' => $this->check_out_date?->toDateString(),
            'nights' => $this->nights,
            'status' => $this->status,
            'base_price' => $this->base_price,
            'current_price' => $this->current_price,
            'currency' => $this->currency,
            'unit' => $this->whenLoaded('unit'),
            'resort' => $this->whenLoaded('resort'),
        ];
    }
}
