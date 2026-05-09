<?php

declare(strict_types=1);

namespace App\Core\Shared\Broadcasting;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Events\AgentStatusChanged;
use App\Modules\CallCenter\Domain\Events\CallConnected;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\Dialer\Domain\Events\DialSkipped;
use Illuminate\Events\Dispatcher;

/**
 * Bridges the domain event bus to the broadcasting bus.
 *
 * This is the single place where "fire and broadcast" is decided. The
 * domain layer stays broadcast-naive; tests that don't care about
 * broadcasting can ignore this entirely.
 *
 * Registered via subscribe() in App\Providers\AppServiceProvider::boot().
 */
final class BroadcastDomainEvents
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            CallInitiated::class => 'onCallInitiated',
            CallConnected::class => 'onCallConnected',
            CallEnded::class => 'onCallEnded',
            AgentStatusChanged::class => 'onAgentStatusChanged',
            DialSkipped::class => 'onDialSkipped',
        ];
    }

    public function onCallInitiated(CallInitiated $event): void
    {
        broadcast(CallEventBroadcast::fromCall($event->call, 'call.initiated'))->toOthers();
    }

    public function onCallConnected(CallConnected $event): void
    {
        broadcast(CallEventBroadcast::fromCall($event->call, 'call.connected'))->toOthers();
    }

    public function onCallEnded(CallEnded $event): void
    {
        broadcast(CallEventBroadcast::fromCall($event->call, 'call.ended'))->toOthers();
    }

    public function onAgentStatusChanged(AgentStatusChanged $event): void
    {
        broadcast(new AgentPresenceBroadcast(
            tenantId: $event->tenantId,
            agentId: $event->agentId,
            from: $event->from,
            to: $event->to,
            callId: $event->callId,
        ))->toOthers();
    }

    public function onDialSkipped(DialSkipped $event): void
    {
        $tenantId = app(TenantContext::class)->id();
        if ($tenantId === null) {
            return; // skipped event outside tenant context — broadcasting has nowhere to send it
        }

        broadcast(new DialSkippedBroadcast(
            tenantId: $tenantId,
            leadId: $event->lead->id,
            reason: $event->reason,
            rejectionCode: $event->rejectionCode,
            sessionId: $event->sessionId,
        ))->toOthers();
    }
}
