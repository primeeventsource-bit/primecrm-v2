import { onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import type Echo from 'laravel-echo';

/**
 * Prime Connect lobby data source.
 *
 * Wraps:
 *   - GET /api/prime-connect/rooms                  initial + refresh
 *   - tenant.{tenantId}.supervisor channel          live updates
 *
 * Lifecycle handled automatically: mounts → load + subscribe; unmounts
 * → leave the channel. The Lobby.vue file can swap its placeholder
 * `activeSessions = ref([...])` for a single line:
 *
 *   const { rooms: activeSessions } = usePrimeConnectRooms(tenantId);
 *
 * The shape returned matches what the lobby's tile renderer already
 * expects (id, twilio_room_sid, room_name, room_status, agent_id,
 * lead_id, scheduled_for, created_at). Keeping the shape stable means
 * no template changes are needed at integration time.
 */

export interface PrimeConnectRoom {
    id: string;
    twilio_room_sid: string | null;
    room_name: string | null;
    room_status: 'created' | 'in_progress' | 'completed' | 'failed' | null;
    medium: 'voice' | 'video' | null;
    agent_id: string | null;
    lead_id: string | null;
    scheduled_for: string | null;
    initiated_at: string | null;
    ended_at: string | null;
    created_at: string | null;
    participants?: Array<{
        id: string;
        identity: string;
        role: string | null;
        user_id: string | null;
        joined_at: string | null;
        left_at: string | null;
    }>;
}

interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

interface Options {
    /** Filter to active (in_progress) rooms only. Defaults to true — the lobby's primary view. */
    activeOnly?: boolean;
    /** Restrict to rooms the current user created. Useful for "my sessions" view. */
    mine?: boolean;
    /** Filter to a specific lead's rooms (for the lead detail page's history strip). */
    leadId?: string;
}

export function usePrimeConnectRooms(tenantId: string, options: Options = {}) {
    const rooms = ref<PrimeConnectRoom[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;
        try {
            const params: Record<string, string | number> = { per_page: 100 };
            if (options.activeOnly !== false) params.room_status = 'in_progress';
            if (options.mine) params.mine = 1;
            if (options.leadId) params.lead_id = options.leadId;

            const { data } = await axios.get<Paginated<PrimeConnectRoom>>('/api/prime-connect/rooms', { params });
            rooms.value = data.data;
        } catch (e: unknown) {
            const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
            error.value = msg ?? 'Could not load rooms.';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Apply a `prime_connect.room.created` payload. Prepends if the room
     * isn't already in the list; updates in place if it is. This is
     * idempotent against the .toOthers() drop on the publishing side
     * (the agent who created the room sees their POST response too).
     */
    function applyRoomCreated(payload: Record<string, unknown>): void {
        const incoming = payload as unknown as PrimeConnectRoom;
        const existing = rooms.value.findIndex((r) => r.id === incoming.id);
        if (existing >= 0) {
            rooms.value[existing] = incoming;
        } else {
            rooms.value = [incoming, ...rooms.value];
        }
    }

    function applyRoomEnded(payload: Record<string, unknown>): void {
        const id = payload.id as string;
        if (options.activeOnly !== false) {
            // Active-only view: ended rooms drop off entirely.
            rooms.value = rooms.value.filter((r) => r.id !== id);
        } else {
            // History view: keep the row, update its status + ended_at.
            const idx = rooms.value.findIndex((r) => r.id === id);
            if (idx >= 0) {
                rooms.value[idx] = {
                    ...rooms.value[idx],
                    room_status: 'completed',
                    ended_at: (payload.ended_at as string) ?? new Date().toISOString(),
                };
            }
        }
    }

    const channel = `tenant.${tenantId}.supervisor`;

    onMounted(() => {
        void load();
        const echo: Echo<'pusher'> | undefined = window.Echo;
        if (!echo) return;

        echo.private(channel)
            .listen('.prime_connect.room.created', applyRoomCreated)
            .listen('.prime_connect.room.ended', applyRoomEnded);
    });

    onBeforeUnmount(() => {
        const echo: Echo<'pusher'> | undefined = window.Echo;
        echo?.leave(channel);
    });

    return { rooms, loading, error, refresh: load };
}
