<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Support\Enums\DealStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdvanceStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'stage' => [
                'required',
                Rule::in(array_map(fn (DealStage $s) => $s->value, DealStage::cases())),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
