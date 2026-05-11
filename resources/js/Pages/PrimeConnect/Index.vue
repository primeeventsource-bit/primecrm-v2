<script setup lang="ts">
/**
 * Prime Connect — video calling surface.
 *
 * The page has two distinct jobs and two distinct views:
 *
 *   • Lobby          → device check + start/schedule/invite, plus a live
 *                      feed of sessions currently in progress and what's
 *                      next on the calendar. This is what a closer lands
 *                      on between calls.
 *   • ActiveCall     → asymmetric video grid with the customer feed
 *                      dominant, self-view inset, live AI coach panel,
 *                      and a floating-pill control bar. This is what the
 *                      closer actually spends hours in.
 *
 * The two views share the page chrome (header pill + tabs) so the
 * service-health signal ("Twilio · Connected") is always visible. The
 * shell owns nothing else — Lobby and ActiveCall are self-contained.
 *
 * Twilio wiring is intentionally not yet here: the lobby's device check
 * runs against the browser's navigator.mediaDevices API directly (no
 * backend dependency for camera/mic preview), and the active-call grid
 * uses placeholder feeds. Swapping in Twilio Video JS room.connect()
 * later replaces the <video> srcObject in ActiveCall — the rest of the
 * UI doesn't move.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Lobby from '@/Components/PrimeConnect/Lobby.vue';
import ActiveCall from '@/Components/PrimeConnect/ActiveCall.vue';
import { usePrimeConnectCall, type CallIntent } from '@/Composables/usePrimeConnectCall';

/* The page owns one call. The composable bundles useTwilioBridge plus
 * the call lifecycle (POST /rooms → mint token → Video.connect →
 * DELETE /rooms on end). ActiveCall.vue receives the bridge as a prop
 * so it can attach() local + remote tracks to its <video> elements. */
const pcCall = usePrimeConnectCall();

type LobbyTab = 'active' | 'scheduled' | 'history' | 'recordings';
type View = 'lobby' | 'call';

const tab = ref<LobbyTab>('active');
const view = ref<View>('lobby');

/* ──────────────────────────────────────────────────────────────────────
 * Twilio service-health pill — driven by the bridge state. While in
 * the lobby (no active call) we show 'connected' optimistically; once
 * a call is in flight, we map bridge.state to the pill states. A
 * 'reconnecting' bridge state surfaces as 'degraded' so the user sees
 * the wobble before video freezes; 'failed' surfaces as 'offline'.
 * ──────────────────────────────────────────────────────────────────── */
type TwilioState = 'connected' | 'degraded' | 'offline';
const twilioState = computed<TwilioState>(() => {
    const s = pcCall.bridge.state.value;
    if (s === 'reconnecting') return 'degraded';
    if (s === 'failed') return 'offline';
    // idle / fetching-token / acquiring-media / connecting / connected
    // / disconnected all render as 'connected' for the pill (we don't
    // want a 'connecting' flash on the pill — it's a service-health
    // signal, not a call-progress signal).
    return 'connected';
});

const twilioPillClass = computed(() => {
    switch (twilioState.value) {
        case 'connected':
            return 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30';
        case 'degraded':
            return 'bg-amber-500/10 text-amber-300 ring-amber-500/30';
        case 'offline':
            return 'bg-rose-500/10 text-rose-300 ring-rose-500/30';
    }
});
const twilioDotClass = computed(() => {
    switch (twilioState.value) {
        case 'connected': return 'bg-emerald-400 animate-pulse-dot';
        case 'degraded':  return 'bg-amber-400';
        case 'offline':   return 'bg-rose-400';
    }
});
const twilioLabel = computed(() => ({
    connected: 'Connected',
    degraded:  'Degraded',
    offline:   'Offline',
}[twilioState.value]));

/* ──────────────────────────────────────────────────────────────────────
 * Tabs. "Active" is the live-now feed; the other three are post-call /
 * forward-looking surfaces. Numbers next to each label are filled by
 * the Lobby once it has data; we only render counts when > 0 so empty
 * tabs read clean.
 * ──────────────────────────────────────────────────────────────────── */
const tabs: { key: LobbyTab; label: string }[] = [
    { key: 'active',     label: 'Active' },
    { key: 'scheduled',  label: 'Scheduled' },
    { key: 'history',    label: 'History' },
    { key: 'recordings', label: 'Recordings' },
];

const counts = ref<Record<LobbyTab, number>>({
    active: 0, scheduled: 0, history: 0, recordings: 0,
});
function onCountsUpdate(next: Record<LobbyTab, number>): void {
    counts.value = next;
}

/* ──────────────────────────────────────────────────────────────────────
 * Page-level tick. The lobby uses it for relative durations on the
 * active-sessions list ("3m ago"); the active call uses it for the
 * recording badge clock and the call timer. One interval, not three.
 * ──────────────────────────────────────────────────────────────────── */
const nowMs = ref(Date.now());
let ticker: number | undefined;
onMounted(() => {
    ticker = window.setInterval(() => (nowMs.value = Date.now()), 1000);

    // Invite-link landing — if the URL carries ?join=<room_name>, jump
    // straight into joining that room. We strip the param from the URL
    // after firing so a tab refresh doesn't re-trigger and a re-share of
    // the page URL from this point doesn't carry stale state. The room
    // id (when present) lets end() know NOT to delete the room — the
    // inviter owns the lifecycle, the invitee just participates.
    const params = new URLSearchParams(window.location.search);
    const joinRoomName = params.get('join');
    const joinRoomId = params.get('roomId') ?? '';
    if (joinRoomName) {
        try {
            params.delete('join');
            params.delete('roomId');
            const cleanQuery = params.toString();
            const cleanUrl = window.location.pathname
                + (cleanQuery ? `?${cleanQuery}` : '')
                + window.location.hash;
            window.history.replaceState({}, '', cleanUrl);
        } catch { /* history API unavailable — non-fatal */ }
        void joinExistingCall(joinRoomName, joinRoomId);
    }
});
onBeforeUnmount(() => { if (ticker !== undefined) window.clearInterval(ticker); });

/* ──────────────────────────────────────────────────────────────────────
 * View transitions. The lobby emits "start" with a session intent (a
 * scheduled-call id, a lead id, or null for an instant ad-hoc call);
 * the active-call view emits "end" when the user hangs up.
 *
 * Real Twilio wiring (added in this commit):
 *   - startCall() awaits the composable's start() which provisions a
 *     room server-side AND connects Video.connect() with a fresh JWT.
 *     We flip view to 'call' BEFORE awaiting so ActiveCall.vue renders
 *     immediately (with the bridge in 'fetching-token' / 'connecting'
 *     state) — the page feels instant and ActiveCall shows its own
 *     connection-quality pill that reflects bridge state.
 *   - endCall() awaits end() which disconnects the bridge (releases
 *     camera/mic) AND DELETEs the room. Best-effort: the local
 *     disconnect always completes even if the server call fails.
 * ──────────────────────────────────────────────────────────────────── */
const startError = ref<string | null>(null);

async function startCall(intent: CallIntent): Promise<void> {
    startError.value = null;
    view.value = 'call';
    try {
        await pcCall.start(intent, { role: 'agent' });
    } catch (e: unknown) {
        // Rollback on failure — user lands back in the lobby with the
        // error message visible. The bridge already cleaned up tracks.
        startError.value = pcCall.lastError.value
            ?? (e instanceof Error ? e.message : 'Could not start the call.');
        view.value = 'lobby';
    }
}

/**
 * Join an existing room (invite-link path). Skips POST /rooms; the
 * server mints a token scoped to the existing room name and the
 * bridge connects. Same UX as startCall otherwise.
 */
async function joinExistingCall(roomName: string, roomId: string): Promise<void> {
    startError.value = null;
    view.value = 'call';
    try {
        await pcCall.start(
            { leadId: null, leadName: 'Joining session', scheduledCallId: null },
            { role: 'agent', joinRoomName: roomName, joinRoomId: roomId },
        );
    } catch (e: unknown) {
        startError.value = pcCall.lastError.value
            ?? (e instanceof Error ? e.message : 'Could not join the call.');
        view.value = 'lobby';
    }
}

async function endCall(): Promise<void> {
    await pcCall.end();
    view.value = 'lobby';
    tab.value = 'history'; // drop them on the just-ended call's row
}

// Browser-tab-close safety net — disconnect the room so we don't leak
// a Twilio session (billed per participant-minute) when the user
// closes the tab mid-call.
onBeforeUnmount(() => {
    if (pcCall.active.value !== null) {
        void pcCall.end();
    }
});

// Auto-recover from a transient failure: if the bridge dies during a
// connect, fall back to the lobby so the user isn't stuck on a black
// screen. The bridge's state-change watch catches this; we just react.
watch(
    () => pcCall.bridge.state.value,
    (s) => {
        if ((s === 'failed' || s === 'disconnected') && view.value === 'call') {
            view.value = 'lobby';
            startError.value = pcCall.bridge.lastError.value ?? startError.value;
        }
    },
);
</script>

<template>
    <!--
      compact-status compresses AgentStatusPill in the header so the
      service-health pill below is the dominant status signal. Same
      pattern the war room uses — dense work surfaces don't need the
      verbose pill competing for attention.
    -->
    <AppLayout title="Prime Connect" compact-status>
        <!--
          Page chrome — only rendered while in the lobby. The active-call
          view takes over the full canvas (you don't want navigation chrome
          competing with a customer's face on a closing call).
        -->
        <div v-if="view === 'lobby'" class="flex h-full flex-col">
            <!--
              Tab row + Twilio pill share one strip directly under the
              AppLayout header. AppLayout already prints "Prime Connect"
              up top, so we don't repeat it here — just the subtitle
              context and the service-health signal.
            -->
            <div class="flex items-center justify-between gap-4 border-b border-deck-line bg-deck-surface/60 px-6">
                <div class="flex items-center gap-1">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="relative flex items-center gap-1.5 px-3 py-2.5 text-sm transition-colors"
                    :class="tab === t.key
                        ? 'text-deck-text'
                        : 'text-deck-soft hover:text-deck-text'"
                    @click="tab = t.key"
                >
                    <span>{{ t.label }}</span>
                    <span
                        v-if="counts[t.key] > 0"
                        class="font-mono tabular-nums text-[11px]"
                        :class="tab === t.key ? 'text-floor-accent' : 'text-deck-dim'"
                    >{{ counts[t.key] }}</span>
                    <!-- active-tab underline -->
                    <span
                        v-if="tab === t.key"
                        class="absolute inset-x-2 -bottom-px h-0.5 bg-floor-accent"
                    ></span>
                </button>
                </div>

                <!-- Twilio service-health pill -->
                <span
                    class="pill ring-1"
                    :class="twilioPillClass"
                    :title="`Twilio video service · ${twilioLabel.toLowerCase()}`"
                >
                    <span class="deck-dot mr-1.5" :class="twilioDotClass"></span>
                    Twilio · {{ twilioLabel }}
                </span>
            </div>

            <!-- Connect-error banner — only when the most recent start
                 attempt failed and we bounced back to the lobby. Cleared
                 on the next start attempt. -->
            <div
                v-if="startError"
                class="border-b border-floor-lose/30 bg-floor-lose/10 px-6 py-2 text-sm text-floor-lose"
            >
                <strong class="font-semibold">Could not start the call:</strong> {{ startError }}
            </div>

            <!-- Lobby body -->
            <div class="flex-1 overflow-auto">
                <Lobby
                    :tab="tab"
                    :now-ms="nowMs"
                    @start="startCall"
                    @counts="onCountsUpdate"
                />
            </div>
        </div>

        <!--
          Active call view — full-bleed inside the slot.
          - intent + answeredAt come from the composable (real Twilio
            connection lifecycle, not placeholder state).
          - bridge is passed so the child can attach() local + remote
            tracks to its <video> elements. The bridge is reactive so
            adding a remote participant mid-call just lights up.
        -->
        <ActiveCall
            v-else
            :intent="pcCall.active.value?.intent ?? null"
            :answered-at="pcCall.active.value?.answeredAt ?? null"
            :room-name="pcCall.active.value?.roomName ?? null"
            :room-id="pcCall.active.value?.roomId ?? null"
            :twilio-state="twilioState"
            :bridge="pcCall.bridge"
            @end="endCall"
        />
    </AppLayout>
</template>
