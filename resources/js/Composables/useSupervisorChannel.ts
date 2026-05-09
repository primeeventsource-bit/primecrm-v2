import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import type { AgentStatusValue } from '@/types/api';

interface AgentSnapshot {
    agent_id: string;
    status: AgentStatusValue;
    name?: string;
    current_call_id?: string | null;
    last_heartbeat_at?: string | null;
}

interface CallEventLog {
    at: string;
    event: string;
    call_id: string;
    agent_id: string | null;
    payload: Record<string, unknown>;
}

interface AlertLog {
    at: string;
    type: 'dial_skipped';
    reason: string;
    rejection_code: string | null;
    lead_id: string;
}

/**
 * Supervisor war-room subscriptions.
 *
 * Listens on the tenant supervisor channel for:
 *   call.initiated / call.connected / call.ended  → live calls feed
 *   agent.presence_changed                        → agent tile updates
 *   dialer.skipped                                → alerts panel
 */
export function useSupervisorChannel(tenantId: string) {
    const agents = reactive<Record<string, AgentSnapshot>>({});
    const liveCallsFeed = ref<CallEventLog[]>([]);
    const alerts = ref<AlertLog[]>([]);

    function applyAgentEvent(payload: Record<string, unknown>): void {
        const agentId = payload.agent_id as string;
        const next = payload.to as AgentStatusValue;
        const callId = (payload.call_id as string | null) ?? null;

        agents[agentId] = {
            agent_id: agentId,
            status: next,
            current_call_id: callId,
            last_heartbeat_at: (payload.at as string) ?? null,
        };
    }

    function applyCallEvent(eventName: string, payload: Record<string, unknown>): void {
        liveCallsFeed.value.unshift({
            at: new Date().toISOString(),
            event: eventName,
            call_id: payload.id as string,
            agent_id: (payload.agent_id as string | null) ?? null,
            payload,
        });
        // Cap the feed at 100 entries — older drops off
        if (liveCallsFeed.value.length > 100) {
            liveCallsFeed.value.length = 100;
        }
    }

    function applyAlert(payload: Record<string, unknown>): void {
        alerts.value.unshift({
            at: (payload.at as string) ?? new Date().toISOString(),
            type: 'dial_skipped',
            reason: (payload.reason as string) ?? 'unknown',
            rejection_code: (payload.rejection_code as string | null) ?? null,
            lead_id: (payload.lead_id as string) ?? '',
        });
        if (alerts.value.length > 50) {
            alerts.value.length = 50;
        }
    }

    onMounted(() => {
        const echo = window.Echo;
        if (!echo) return;

        const channel = echo.private(`tenant.${tenantId}.supervisor`);
        channel.listen('.call.initiated', (p: Record<string, unknown>) => applyCallEvent('call.initiated', p));
        channel.listen('.call.connected', (p: Record<string, unknown>) => applyCallEvent('call.connected', p));
        channel.listen('.call.ended', (p: Record<string, unknown>) => applyCallEvent('call.ended', p));
        channel.listen('.agent.presence_changed', (p: Record<string, unknown>) => applyAgentEvent(p));
        channel.listen('.dialer.skipped', (p: Record<string, unknown>) => applyAlert(p));
    });

    onBeforeUnmount(() => {
        window.Echo?.leave(`tenant.${tenantId}.supervisor`);
    });

    return { agents, liveCallsFeed, alerts };
}
