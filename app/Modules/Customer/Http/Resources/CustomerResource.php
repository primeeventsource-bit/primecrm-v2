<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Customer\Domain\Models\Customer
 */
final class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'source' => $this->source,
            'lifetime_value' => $this->lifetime_value,
            'total_deals' => $this->total_deals,
            'total_bookings' => $this->total_bookings,
            'first_purchase_at' => $this->first_purchase_at?->toIso8601String(),
            'last_purchase_at' => $this->last_purchase_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
