<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Requests;

use App\Support\Enums\AgentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeAgentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Agents can change their own status. Supervisors can change anyone's
        // (handled in the controller, since we need to compare target vs caller).
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(array_map(fn (AgentStatus $s) => $s->value, AgentStatus::cases())),
            ],
            'agent_id' => ['nullable', 'uuid'], // supervisor override
            'session_id' => ['nullable', 'uuid'],
        ];
    }
}
