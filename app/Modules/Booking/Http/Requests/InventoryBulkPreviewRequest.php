<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the upload step of bulk inventory import.
 *
 * Accepts .csv, .xlsx, .xls. The actual parsing happens in the
 * controller via InventoryCsvParser; here we just gate the upload
 * size and MIME so an oversized binary doesn't reach the parser.
 *
 * 4 MB ceiling: 50k rows in a tight CSV is roughly 3 MB, so this
 * leaves headroom without inviting denial-of-service via huge files.
 */
final class InventoryBulkPreviewRequest extends FormRequest
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
                'max:4096', // KB → 4 MB
                // mimes: extension-only allowlist. We don't trust
                // browser-reported MIME — relying on extension keeps
                // the import predictable across platforms.
                'mimes:csv,txt,xlsx,xls',
            ],
        ];
    }
}
