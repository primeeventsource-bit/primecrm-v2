<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Requests;

use App\Support\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'min:7', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(array_column(LeadStatus::cases(), 'value'))],
            'substatus' => ['sometimes', 'nullable', 'string', 'max:64'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'hot'])],
            'resort_interest' => ['sometimes', 'nullable', 'string', 'max:120'],
            'property_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'estimated_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'do_not_contact_until' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
