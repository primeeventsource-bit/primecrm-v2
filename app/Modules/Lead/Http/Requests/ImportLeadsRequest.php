<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Only supervisors and above can run bulk imports — bad CSV maps can
        // poison thousands of records. The dedup engine catches phone collisions
        // but field mappings aren't reversible without a backup restore.
        return $user !== null && $user->role->canSupervise();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'], // 50 MB
            'column_mapping' => ['required', 'array'],
            // mapping is csv_header_name => lead_field_name
            'column_mapping.*' => ['required', 'string', 'max:64'],
            'source' => ['required', 'string', 'max:64'],
        ];
    }
}
