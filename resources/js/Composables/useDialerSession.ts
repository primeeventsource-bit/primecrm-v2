import { computed, ref } from 'vue';
import axios from 'axios';
import type { DialSession } from '@/types/api';

/**
 * Dialer-session lifecycle for the agent's UI.
 *
 *   start({ campaignId, mode })  → POST /api/dialer/sessions
 *   pause / resume / stop        → matching endpoints
 *   reload()                     → GET /api/dialer/sessions/active
 *
 * The composable doesn't subscribe to WebSocket — the page does, and
 * pushes session updates via setFromBroadcast when it receives them.
 */
export function useDialerSession() {
    const session = ref<DialSession | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const isActive = computed(() => session.value?.status === 'active');
    const isPaused = computed(() => session.value?.status === 'paused');

    async function reload(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await axios.get<{ data: DialSession } | null>('/api/dialer/sessions/active');
            session.value = data ? (data.data ?? null) : null;
        } catch (e) {
            error.value = (e as Error).message;
        } finally {
            loading.value = false;
        }
    }

    async function start(options: { campaignId?: string; mode?: string } = {}): Promise<void> {
        loading.value = true;
        try {
            const payload: Record<string, string> = {};
            if (options.campaignId) payload.campaign_id = options.campaignId;
            if (options.mode) payload.mode = options.mode;

            const { data } = await axios.post<{ data: DialSession }>('/api/dialer/sessions', payload);
            session.value = data.data;
        } finally {
            loading.value = false;
        }
    }

    async function pause(): Promise<void> {
        if (!session.value) return;
        const { data } = await axios.post<{ data: DialSession }>(`/api/dialer/sessions/${session.value.id}/pause`);
        session.value = data.data;
    }

    async function resume(): Promise<void> {
        if (!session.value) return;
        const { data } = await axios.post<{ data: DialSession }>(`/api/dialer/sessions/${session.value.id}/resume`);
        session.value = data.data;
    }

    async function stop(): Promise<void> {
        if (!session.value) return;
        const { data } = await axios.post<{ data: DialSession }>(`/api/dialer/sessions/${session.value.id}/stop`);
        session.value = data.data;
    }

    async function dialNow(leadId: string): Promise<{ ok: boolean; reason?: string }> {
        if (!session.value) return { ok: false, reason: 'no_session' };

        try {
            await axios.post(`/api/dialer/sessions/${session.value.id}/dial-now`, { lead_id: leadId });
            return { ok: true };
        } catch (e: unknown) {
            const err = e as { response?: { data?: { decision?: { reason?: string }; error?: string } } };
            const reason = err.response?.data?.decision?.reason ?? err.response?.data?.error ?? 'unknown_error';
            return { ok: false, reason };
        }
    }

    return {
        session,
        isActive,
        isPaused,
        loading,
        error,
        reload,
        start,
        pause,
        resume,
        stop,
        dialNow,
    };
}
