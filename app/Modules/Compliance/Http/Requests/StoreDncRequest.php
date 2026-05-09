<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Domain\Enums\DncSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDncRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Only supervisors+ can add DNC entries via the UI. Federal/state list
        // imports happen via queued jobs, not this endpoint.
        return $user !== null && $user->role->canSupervise();
    }

    public function rules(): array
    {
        // Federal/state/wireless are global lists managed by import jobs;
        // we don't accept them through this user-facing endpoint to prevent
        // operators from accidentally polluting the global lists.
        $editableSources = array_values(array_filter(
            array_map(fn (DncSource $s) => $s->value, DncSource::cases()),
            fn (string $source) => DncSource::from($source)->isUserEditable(),
        ));

        return [
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'source' => ['required', Rule::in($editableSources)],
            'reason' => ['nullable', 'string', 'max:500'],
            'effective_date' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }
}
