<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Role a participant plays in a video room. Encoded into Twilio identity
 * strings as `{role}:{userId}` so the supervisor controller can filter
 * the participant roster on join without a DB lookup.
 *
 * Three supervisor variants exist because the audio-track subscription
 * rules differ: Listen mints a token without an outbound audio grant;
 * Whisper publishes audio routed only to the agent participant; Barge
 * publishes audio everyone hears.
 */
enum RoomParticipantRole: string
{
    case Agent = 'agent';
    case Customer = 'customer';
    case SupervisorListen = 'supervisor_listen';
    case SupervisorWhisper = 'supervisor_whisper';
    case SupervisorBarge = 'supervisor_barge';

    public function isSupervisor(): bool
    {
        return in_array($this, [
            self::SupervisorListen,
            self::SupervisorWhisper,
            self::SupervisorBarge,
        ], true);
    }

    /** Identity prefix used in Twilio access tokens; must round-trip with parseIdentity(). */
    public function identityPrefix(): string
    {
        return $this->value;
    }

    /**
     * Recover the role from an identity string like "agent:01HX...".
     * Returns null if the prefix is unknown so callers can decide whether
     * to reject the participant or treat as a generic user.
     */
    public static function fromIdentity(string $identity): ?self
    {
        $prefix = explode(':', $identity, 2)[0] ?? '';

        return self::tryFrom($prefix);
    }
}
