<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Domain\Enums\ConsentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'consent_type' => [
                'required',
                Rule::in(array_map(fn (ConsentType $t) => $t->value, ConsentType::cases())),
            ],
            'source' => ['required', 'string', 'max:64'],
            // At least ONE of source_url+ip+ua (web), recording_url (verbal),
            // or consent_text_snapshot (paper) — enforced after validation.
            'source_url' => ['nullable', 'url', 'max:500'],
            'source_ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'recording_url' => ['nullable', 'url', 'max:500'],
            'consent_text_snapshot' => ['nullable', 'array'],
            'consented_at' => ['nullable', 'date'],
            'lead_id' => ['nullable', 'uuid', 'exists:leads,id'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $hasWeb = $this->filled('source_url') && $this->filled('source_ip');
            $hasRecording = $this->filled('recording_url');
            $hasPaper = $this->filled('consent_text_snapshot');

            if (! $hasWeb && ! $hasRecording && ! $hasPaper) {
                $v->errors()->add(
                    'source',
                    'Consent must include either web evidence (source_url + source_ip), '
                    .'a recording_url, or a consent_text_snapshot.',
                );
            }
        });
    }
}
