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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Lobby from '@/Components/PrimeConnect/Lobby.vue';
import ActiveCall from '@/Components/PrimeConnect/ActiveCall.vue';

type LobbyTab = 'active' | 'scheduled' | 'history' | 'recordings';
type View = 'lobby' | 'call';

const tab = ref<LobbyTab>('active');
const view = ref<View>('lobby');

/* ──────────────────────────────────────────────────────────────────────
 * Twilio service-health pill. Until the access-token endpoint is wired,
 * we treat the connection as healthy by default; a real implementation
 * subscribes to Twilio Video's `connectionStateChanged` (or polls a
 * /api/twilio/health endpoint) and downgrades this to 'degraded' or
 * 'offline' so the rest of the UI can render fallback affordances
 * (e.g. voice-only mode) instead of dead buttons.
 * ──────────────────────────────────────────────────────────────────── */
type TwilioState = 'connected' | 'degraded' | 'offline';
const twilioState = ref<TwilioState>('connected');

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
onMounted(() => { ticker = window.setInterval(() => (nowMs.value = Date.now()), 1000); });
onBeforeUnmount(() => { if (ticker !== undefined) window.clearInterval(ticker); });

/* ──────────────────────────────────────────────────────────────────────
 * View transitions. The lobby emits "start" with a session intent (a
 * scheduled-call id, a lead id, or null for an instant ad-hoc call);
 * the active-call view emits "end" when the user hangs up. The shell
 * just routes between the two — neither child knows about the other.
 * ──────────────────────────────────────────────────────────────────── */
interface CallIntent {
    leadId: string | null;
    leadName: string | null;
    scheduledCallId: string | null;
}
const callIntent = ref<CallIntent | null>(null);

function startCall(intent: CallIntent): void {
    callIntent.value = intent;
    view.value = 'call';
}
function endCall(): void {
    callIntent.value = null;
    view.value = 'lobby';
    tab.value = 'history'; // drop them on the just-ended call's row
}
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
          Active call view — full-bleed inside the slot. Owns its own
          tick via useCallTimer; we don't pass nowMs because the timer
          composable handles its own interval anchored to answered_at.
        -->
        <ActiveCall
            v-else
            :intent="callIntent"
            :twilio-state="twilioState"
            @end="endCall"
        />
    </AppLayout>
</template>
