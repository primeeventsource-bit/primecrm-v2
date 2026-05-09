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
}
