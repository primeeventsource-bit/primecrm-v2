<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInstantRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user in the tenant can start a room.
        // The room is owned by them — they're the de facto agent.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Optional context: linking the room to a lead surfaces it on
            // the lead detail page's communication timeline (when notes
            // module gains a Call/Room kind in S5).
            'lead_id' => ['nullable', 'uuid', 'exists:leads,id'],
            // Free-text label shown in the lobby's active-sessions list.
            // Defaults to "{agent first name} · {customer first name}" or
            // a generic label inside the action when omitted.
            'room_name' => ['nullable', 'string', 'max:128'],
            // Anything else the lobby wants to round-trip into the room
            // (deal context for the Live Coach, invited identity hints).
            'lobby_metadata' => ['nullable', 'array'],
        ];
    }
}
