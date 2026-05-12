<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the commit step of bulk inventory import.
 *
 * The preview_token came from a prior /bulk-preview call; the
 * approved_* arrays carry which new resorts/units the operator
 * actually wants the importer to create. Unchecked entities cause
 * their dependent rows to be skipped, not failed.
 */
final class InventoryBulkImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'uuid'],
            'approved_resort_keys' => ['nullable', 'array'],
            'approved_resort_keys.*' => ['string', 'max:200'],
            'approved_unit_keys' => ['nullable', 'array'],
            'approved_unit_keys.*' => ['string', 'max:200'],
        ];
    }
}
