<?php

declare(strict_types=1);

namespace App\Core\Shared\Broadcasting;

/**
 * Centralised channel-name builder. The strings here MUST match the
 * patterns in routes/channels.php — drift between the two is a silent
 * "events fire but nothing receives them" failure mode.
 */
final class BroadcastChannel
{
    public static function agent(string $tenantId, string $agentId): string
    {
        return "tenant.{$tenantId}.agent.{$agentId}";
    }

    public static function supervisor(string $tenantId): string
    {
        return "tenant.{$tenantId}.supervisor";
    }

    /**
     * Per-room channel for Prime Connect video rooms. Participant events
     * (joined / disconnected) and in-call coordination broadcasts ride
     * here so they don't fan out to every supervisor's WebSocket pipe.
     *
     * Authorized only for users who are listed as participants on the
     * room OR users with canSupervise() in the same tenant — see the
     * `tenant.{tenantId}.room.{roomSid}` block in routes/channels.php.
     */
    public static function room(string $tenantId, string $roomSid): string
    {
        return "tenant.{$tenantId}.room.{$roomSid}";
    }
}
