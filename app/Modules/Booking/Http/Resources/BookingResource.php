<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Booking\Domain\Models\Booking
 */
final class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'deal_id' => $this->deal_id,
            'inventory_availability_id' => $this->inventory_availability_id,
            'agent_id' => $this->agent_id,
            'status' => $this->status,
            'confirmation_number' => $this->confirmation_number,
            'total_price' => $this->total_price,
            'paid_amount' => $this->paid_amount,
            'currency' => $this->currency,
            'check_in_date' => $this->check_in_date?->toDateString(),
            'check_out_date' => $this->check_out_date?->toDateString(),
            'guest_details' => $this->guest_details,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
