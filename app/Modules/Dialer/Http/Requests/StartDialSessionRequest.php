<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Http\Requests;

use App\Support\Enums\DialerMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartDialSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role->canTakeCalls();
    }

    public function rules(): array
    {
        return [
            'campaign_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
            'mode' => [
                'nullable',
                Rule::in(array_map(fn (DialerMode $m) => $m->value, DialerMode::cases())),
            ],
        ];
    }
}
