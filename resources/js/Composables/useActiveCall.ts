import { computed, ref } from 'vue';
import axios from 'axios';
import type { Call, Lead } from '@/types/api';

/**
 * Tracks the agent's current live call.
 *
 * Updated by:
 *   - HTTP fetch on mount (any call already in progress)
 *   - WebSocket events (call.initiated, call.connected, call.ended)
 *
 * Exposes:
 *   - call (Call | null)
 *   - lead (Lead | null) — fetched alongside the call when it lands
 *   - timer state (handled by useCallTimer composing on call.answered_at)
 *   - end / disposition actions
 */
export function useActiveCall() {
    const call = ref<Call | null>(null);
    const lead = ref<Lead | null>(null);
    const loading = ref(false);

    const isLive = computed(() => {
        const s = call.value?.status;
        return s === 'queued' || s === 'initiated' || s === 'ringing' || s === 'in_progress';
    });

    const isConnected = computed(() => call.value?.status === 'in_progress');

    async function loadActive(agentId: string): Promise<void> {
        loading.value = true;
        try {
            const { data } = await axios.get<{ data: Call[] }>('/api/calls', {
                params: { agent_id: agentId, live: true, per_page: 1 },
            });
            call.value = data.data[0] ?? null;
            if (call.value?.lead_id) {
                await loadLead(call.value.lead_id);
            }
        } finally {
            loading.value = false;
        }
    }

    async function loadLead(leadId: string): Promise<void> {
        const { data } = await axios.get<{ data: Lead }>(`/api/leads/${leadId}`);
        lead.value = data.data;
    }

    function applyBroadcast(eventName: string, payload: Record<string, unknown>): void {
        // Only react if the broadcast is for the agent's own call.
        const incomingCallId = payload.id as string | undefined;
        if (!incomingCallId) return;

        if (eventName === 'call.initiated' || eventName === 'call.connected') {
            call.value = {
                ...(call.value ?? ({} as Call)),
                ...(payload as Partial<Call>),
                id: incomingCallId,
            } as Call;

            const incomingLeadId = payload.lead_id as string | undefined;
            if (incomingLeadId && lead.value?.id !== incomingLeadId) {
                void loadLead(incomingLeadId);
            }
        }

        if (eventName === 'call.ended' && call.value?.id === incomingCallId) {
            call.value = { ...call.value, ...(payload as Partial<Call>) } as Call;
        }
    }

    async function endCall(): Promise<void> {
        if (!call.value) return;
        const { data } = await axios.post<{ data: Call }>(`/api/calls/${call.value.id}/end`);
        call.value = data.data;
    }

    async function setDisposition(disposition: string, notes?: string): Promise<void> {
        if (!call.value) return;
        const { data } = await axios.post<{ data: Call }>(
            `/api/calls/${call.value.id}/disposition`,
            { disposition, notes: notes ?? null },
        );
        call.value = data.data;
    }

    function clear(): void {
        call.value = null;
        lead.value = null;
    }

    return {
        call,
        lead,
        loading,
        isLive,
        isConnected,
        loadActive,
        applyBroadcast,
        endCall,
        setDisposition,
        clear,
    };
}
