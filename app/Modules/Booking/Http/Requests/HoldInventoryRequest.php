<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class HoldInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'inventory_availability_id' => ['required', 'uuid', 'exists:inventory_availability,id'],
            'lead_id' => ['nullable', 'uuid', 'exists:leads,id'],
            'deal_id' => ['nullable', 'uuid', 'exists:deals,id'],
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
        ];
    }
}
