<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Discriminator on the calls table.
 *
 * The same `calls` row models a 1:1 voice dial (existing dialer) and a
 * Prime Connect video room (1..N participants tracked in
 * call_participants). Voice rows leave the room_* columns null; video
 * rows leave the SIP/voice-only fields null.
 *
 * Querying the dialer pipeline always filters `medium = voice`; the
 * Prime Connect lobby filters `medium = video`.
 */
enum CallMedium: string
{
    case Voice = 'voice';
    case Video = 'video';

    public function isVideo(): bool
    {
        return $this === self::Video;
    }
}
