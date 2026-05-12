<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a single "add inventory" payload.
 *
 * The form lets the operator either pick an existing resort/unit or
 * fill in new-entity fields inline. The controller resolves whichever
 * pair of inputs is present:
 *
 *   - resort_id supplied  → use it; ignore resort_new
 *   - resort_new supplied → create the resort, use the new id
 *
 * Same logic for unit_id / unit_new. The validation rules below allow
 * both shapes; the controller enforces "one of (id|new) is required".
 */
final class StoreInventoryAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant middleware enforces scope
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Resort selection — either existing id OR new-resort fields.
            'resort_id' => ['nullable', 'uuid', 'required_without:resort_new'],
            'resort_new' => ['nullable', 'array', 'required_without:resort_id'],
            'resort_new.name' => ['required_with:resort_new', 'string', 'max:200'],
            'resort_new.brand' => ['nullable', 'string', 'max:120'],
            'resort_new.city' => ['required_with:resort_new', 'string', 'max:120'],
            'resort_new.state' => ['required_with:resort_new', 'string', 'size:2'],
            'resort_new.country' => ['nullable', 'string', 'size:2'],
            'resort_new.timezone' => ['nullable', 'string', 'max:64'],

            // Unit selection — either existing id OR new-unit fields.
            'unit_id' => ['nullable', 'uuid', 'required_without:unit_new'],
            'unit_new' => ['nullable', 'array', 'required_without:unit_id'],
            'unit_new.unit_type' => ['required_with:unit_new', Rule::in([
                'studio', '1br', '2br', '3br', 'presidential',
            ])],
            'unit_new.sleeps' => ['required_with:unit_new', 'integer', 'min:1', 'max:20'],
            'unit_new.features' => ['nullable', 'array'],
            'unit_new.features.*' => ['string', 'max:64'],

            // Availability — the actual inventory row we're creating.
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
