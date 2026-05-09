<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Lead\Domain\Models\Lead
 */
final class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
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
            'status' => $this->status?->value,
            'substatus' => $this->substatus,
            'priority' => $this->priority?->value,
            'score' => $this->score,
            'source' => $this->source,
            'source_campaign' => $this->source_campaign,
            'source_medium' => $this->source_medium,
            'resort_interest' => $this->resort_interest,
            'property_type' => $this->property_type,
            'estimated_value' => $this->estimated_value,
            'assigned_agent_id' => $this->assigned_agent_id,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'last_contacted_at' => $this->last_contacted_at?->toIso8601String(),
            'contact_attempts' => $this->contact_attempts,
            'is_on_dnc' => $this->is_on_dnc,
            'has_express_consent' => $this->has_express_consent,
            'consent_at' => $this->consent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
