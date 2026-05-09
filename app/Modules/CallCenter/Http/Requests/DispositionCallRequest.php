<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Requests;

use App\Support\Enums\CallDisposition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DispositionCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'disposition' => [
                'required',
                Rule::in(array_map(fn (CallDisposition $d) => $d->value, CallDisposition::cases())),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
