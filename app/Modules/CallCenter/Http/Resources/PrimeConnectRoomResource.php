<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lobby-shaped projection of a calls row where medium='video'.
 *
 * Distinct from CallResource (the voice projection) — voice rows
 * include status/disposition/sentiment fields the lobby doesn't render
 * and exclude room_name/scheduled_for which it does.
 *
 * @mixin \App\Modules\CallCenter\Domain\Models\Call
 */
final class PrimeConnectRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // War-room flag rides on lobby_metadata so we don't need a column
        // migration just for a boolean. Anything that needs to surface to
        // a supervisor (or the agent's own UI) can read this without
        // re-resolving the relation.
        $lobby = is_array($this->lobby_metadata) ? $this->lobby_metadata : [];

        return [
            'id' => $this->id,
            'twilio_room_sid' => $this->twilio_room_sid,
            'room_name' => $this->room_name,
            'room_status' => $this->room_status?->value,
            'medium' => $this->medium?->value,
            'agent_id' => $this->agent_id,
            'lead_id' => $this->lead_id,
            // Convenience strings, populated when the relations are
            // eager-loaded by the caller. The lobby ALWAYS eager-loads;
            // the in-call show endpoint does too. When not loaded, the
            // field is omitted from the response entirely.
            'lead_name' => $this->whenLoaded('lead', fn () => $this->lead?->fullName()),
            'agent_name' => $this->whenLoaded('agent', fn () => $this->agent?->fullName()),
            'flagged' => (bool) ($lobby['war_room_flag'] ?? false),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The participant roster — included whenever the relation has
            // been eager-loaded. The lobby's tile renders avatars for
            // who's currently in the room.
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($p) => [
                'id' => $p->id,
                'identity' => $p->identity,
                'role' => $p->role?->value,
                'user_id' => $p->user_id,
                'joined_at' => $p->joined_at?->toIso8601String(),
                'left_at' => $p->left_at?->toIso8601String(),
            ])),
        ];
    }
}
