<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * File-upload gate for bulk rental-booking import.
 *
 * Same 4 MB envelope as inventory + listing bulk endpoints so the
 * operator sees consistent behavior.
 */
final class RentalBookingBulkPreviewRequest extends FormRequest
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
