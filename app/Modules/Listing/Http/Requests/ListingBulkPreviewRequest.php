<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * File-upload gate for bulk listing import.
 *
 * 4 MB ceiling matches the inventory importer — same envelope on
 * both endpoints so the operator's mental model is consistent. The
 * actual parsing happens in ListingCsvParser; this just keeps an
 * oversized binary off the parser.
 */
final class ListingBulkPreviewRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:4096',
                'mimes:csv,txt,xlsx,xls',
            ],
        ];
    }
}
