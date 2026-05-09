<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant context is enforced by the route middleware; any user
        // authorized to reach this endpoint can create leads in their tenant.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'min:7', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'source' => ['required', 'string', 'max:64'],
            'source_campaign' => ['nullable', 'string', 'max:120'],
            'source_medium' => ['nullable', 'string', 'max:64'],
            'source_metadata' => ['nullable', 'array'],
            'resort_interest' => ['nullable', 'string', 'max:120'],
            'property_type' => ['nullable', 'string', 'max:64'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'hot'])],
        ];
    }
}
