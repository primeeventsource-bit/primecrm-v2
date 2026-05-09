<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Support\Enums\DealStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'uuid', 'exists:leads,id'],
            'agent_id' => ['required', 'uuid', 'exists:users,id'],
            'fronter_id' => ['nullable', 'uuid', 'exists:users,id'],
            'additional_closer_ids' => ['nullable', 'array'],
            'additional_closer_ids.*' => ['uuid', 'exists:users,id'],
            'total_value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'snr_amount' => ['nullable', 'numeric', 'min:0'],
            'vd_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'pitch_data' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'stage' => [
                'nullable',
                Rule::in(array_map(fn (DealStage $s) => $s->value, DealStage::cases())),
            ],
            'expected_close_at' => ['nullable', 'date'],
        ];
    }
}
