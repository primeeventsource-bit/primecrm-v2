<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Commits a previewed listing bulk import.
 *
 * The two approval lists carry which new owners (leads) and new
 * properties the operator wants the importer to create. Rows whose
 * required new entity wasn't approved are skipped, not failed —
 * matches the inventory bulk semantics.
 */
final class ListingBulkImportRequest extends FormRequest
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
            'approved_owner_keys' => ['nullable', 'array'],
            'approved_owner_keys.*' => ['string', 'max:200'],
            'approved_property_keys' => ['nullable', 'array'],
            'approved_property_keys.*' => ['string', 'max:300'],
        ];
    }
}
