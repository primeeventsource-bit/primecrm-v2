<?php

declare(strict_types=1);

namespace App\Modules\Note\Http\Requests;

use App\Modules\Note\Domain\Models\Note;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'notable_type' => ['required', 'string', Rule::in(['lead', 'customer'])],
            'notable_id' => ['required', 'uuid'],
            'kind' => ['nullable', Rule::in([
                Note::KIND_NOTE,
                Note::KIND_CALL,
                Note::KIND_EMAIL,
                Note::KIND_SMS,
                Note::KIND_SYSTEM,
            ])],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
