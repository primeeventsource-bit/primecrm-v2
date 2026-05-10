<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Requests;

use App\Support\Enums\RoomParticipantRole;
use App\Support\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/prime-connect/access-token.
 *
 * The role drives both the JWT identity prefix AND a server-side
 * authorization check — only supervisors can mint supervisor_* tokens
 * (otherwise an agent could whisper into anyone's call by lying about
 * their role). The actual audio routing is enforced client-side by
 * Twilio Track Subscriptions in S5; this request gate is the first line
 * of defense.
 */
final class MintAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $role = $this->input('role');
        if (in_array($role, [
            RoomParticipantRole::SupervisorListen->value,
            RoomParticipantRole::SupervisorWhisper->value,
            RoomParticipantRole::SupervisorBarge->value,
        ], true)) {
            return $user->role instanceof UserRole && $user->role->canSupervise();
        }

        return true;
    }

    public function rules(): array
    {
        return [
            // Optional pin to a specific room. When omitted the token can join any
            // room the identity is otherwise authorized for (used by lobby reconnect).
            'room_name' => ['nullable', 'string', 'max:128'],
            'role' => [
                'required',
                Rule::in(array_map(
                    fn (RoomParticipantRole $r) => $r->value,
                    RoomParticipantRole::cases()
                )),
            ],
            // Override the default TTL (mainly for short-lived supervisor tokens).
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
        ];
    }
}
