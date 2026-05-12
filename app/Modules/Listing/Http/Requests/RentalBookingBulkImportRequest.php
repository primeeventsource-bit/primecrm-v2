<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Commits a previewed rental-booking import.
 *
 * Bookings, unlike listings or inventory, have no "new entity"
 * approval step — the listing must already exist for a row to
 * succeed. We keep the preview/import split anyway so the operator
 * sees row-level errors before any DB writes, and because the
 * commit step is the natural place to gate on operator confirmation
 * ("yes, send owner notifications").
 */
final class RentalBookingBulkImportRequest extends FormRequest
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
            'notify_owners' => ['nullable', 'boolean'],
        ];
    }
}
