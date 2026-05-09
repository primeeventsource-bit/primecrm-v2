<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Manual reassign is a supervisor action. Auto-routing endpoint
        // (no agent_id) is also restricted to supervisors to prevent
        // agents farming routing churn.
        return $user !== null && $user->role->canSupervise();
    }

    public function rules(): array
    {
        return [
            'agent_id' => ['nullable', 'uuid', 'exists:users,id'],
            'mode' => ['nullable', Rule::in(['round_robin', 'performance', 'skill_based'])],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
