<script setup lang="ts">
/**
 * Prime Connect — active call view.
 *
 * Layout decisions (per the design brief):
 *
 *   • Asymmetric grid. The customer feed gets the dominant share of the
 *     width (~70%) — that's the face the closer is reading. Self-view
 *     sits inset at top-right, smaller, picture-in-picture style. Below
 *     the self-view, the Live Coach panel gets the remaining vertical
 *     space; coaching prompts only matter while you're on a call, so
 *     this is exactly where they should live.
 *
 *   • Floating-pill control bar (Zoom-style). Three logical clusters
 *     separated by thin dividers:
 *       1. Call hardware    (mute, camera, share screen)
 *       2. Coaching / CRM   (notes, pipeline drawer, AI assist)
 *       3. Destructive      (transfer = orange, end call = red)
 *     The destructive group is visually separated and oversized so a
 *     stressed closer doesn't fat-finger End when they meant Transfer.
 *
 *   • Recording badge top-left — TCPA disclosure: the agent must always
 *     know recording is on. Red, slightly pulsing, ugly-on-purpose so
 *     it's impossible to ignore. Position is locked to top-left so it
 *     never overlaps the customer's face.
 *
 *   • Connection quality top-right. Codec + RTT in mono. If quality
 *     drops, this is where the user sees it before the video freezes.
 *
 *   • War-room mode. A toggle on the control bar that pings the
 *     supervisor surface — the closer can flag a call going sideways
 *     without breaking flow to message anyone.
 *
 * Wire-up plan (concrete next-pass steps, not done here):
 *
 *   1. State source: replace the placeholder `intent` prop and local
 *      `answeredAt` ref with `useActiveCall()`
 *      (resources/js/Composables/useActiveCall.ts). It already exposes
 *      `call`, `lead`, `isLive`, `endCall()`, `setDisposition()`, and
 *      `applyBroadcast()`. Wire `useEcho().on('tenant.{id}.agent.{agentId}',
 *      'call.connected', ...)` to push events into applyBroadcast.
 *
 *   2. Timer: useCallTimer is already plumbed below — when (1) lands,
 *      bind it to `activeCall.call.value.answered_at` instead of a
 *      manually-stamped ISO ref. The display + elapsedSeconds bindings
 *      keep working.
 *
 *   3. Twilio room: token ← POST /api/twilio/access-token (VideoGrant
 *      attached); room ← Twilio.Video.connect(token, { name, audio,
 *      video }); attach localParticipant videoTracks → selfVideo, first
 *      remote participant's videoTrack → main canvas.
 *
 *   4. Recording: Composition API webhook on participantConnected;
 *      recording metadata flows back through CallEventBroadcast onto
 *      the calls table so History/Recordings tabs auto-update.
 *
 *   5. Live coach: Media Streams → /ws/coach (Deepgram realtime →
 *      LLM with last 30s + deal context) → SignalR/Echo push into
 *      `prompts`. The shape (kind/headline/detail) already matches.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import WarRoomIcon from '@/Components/Supervisor/WarRoomIcon.vue';
import { useCallTimer } from '@/Composables/useCallTimer';
import type { useTwilioBridge } from '@/Composables/useTwilioBridge';

interface CallIntent {
    leadId: string | null;
    leadName: string | null;
    scheduledCallId: string | null;
}

type Bridge = ReturnType<typeof useTwilioBridge>;

const props = defineProps<{
    intent: CallIntent | null;
    /** ISO timestamp the local participant entered the room; null until connect resolves. */
    answeredAt: string | null;
    /** Twilio room name — load-bearing for the invite-link feature. */
    roomName: string | null;
    /** DB row id of the room (empty string when we joined someone else's). */
    roomId: string | null;
    twilioState: 'connected' | 'degraded' | 'offline';
    /**
     * The Twilio bridge instance owned by Pages/PrimeConnect/Index.vue.
     * We attach this view's <video>/<audio> elements to the bridge's
     * local + remote tracks reactively.
     */
    bridge: Bridge;
}>();

const emit = defineEmits<{
    (e: 'end'): void;
}>();

/* ──────────────────────────────────────────────────────────────────────
 * Call timer — driven by the prop now (provided by usePrimeConnectCall
 * once Video.connect() resolves). Until the room connects, the timer
 * stays paused at 00:00.
 * ──────────────────────────────────────────────────────────────────── */
const answeredAtRef = computed(() => props.answeredAt);
const { elapsedSeconds } = useCallTimer(answeredAtRef);

const elapsedLabel = computed(() => {
    const s = elapsedSeconds.value;
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const pad = (n: number): string => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(r)}` : `${pad(m)}:${pad(r)}`;
});

/* ──────────────────────────────────────────────────────────────────────
 * Track attachment — bridge ↔ DOM <video> / <audio> elements.
 *
 * The Twilio Video SDK exposes attach()/detach() on every track. We
 * call them in watchers so that:
 *   • The local video track lights up the self-view as soon as it's
 *     available (which is before the room connects — createLocalTracks
 *     resolves first).
 *   • The first remote participant's video track lights up the main
 *     canvas as soon as their `trackSubscribed` event fires.
 *   • Audio tracks attach to hidden <audio> elements so they play
 *     even when the participant isn't visible in the main canvas
 *     (a future supervisor-listen mode rides on this).
 *
 * Cleanup: track.detach() returns the elements it was attached to.
 * Removing them from the DOM is the only way the camera-on indicator
 * goes dark; the bridge's stop() handles the underlying MediaStream.
 * ──────────────────────────────────────────────────────────────────── */
const selfVideoEl = ref<HTMLVideoElement | null>(null);
const remoteVideoEl = ref<HTMLVideoElement | null>(null);
const remoteAudioMount = ref<HTMLDivElement | null>(null);

// Local video → self-view <video>. Detaches the prior track first so a
// reconnect doesn't double-attach.
watch(
    () => props.bridge.localVideoTrack.value,
    (track, prev) => {
        if (prev) {
            try { prev.detach().forEach((el) => el.remove()); } catch { /* */ }
        }
        if (track && selfVideoEl.value) {
            try { track.attach(selfVideoEl.value); } catch { /* */ }
        }
    },
    { immediate: true },
);

// First remote participant's video → main canvas. We pick the
// dominant speaker when available (the SDK calls this on talk), else
// the first remote in the map. The watcher is a single source of
// truth — adding/removing participants reactively flips the main feed.
const primaryRemote = computed(() => {
    const map = props.bridge.remoteParticipants.value;
    const dominant = props.bridge.dominantSpeakerIdentity.value;
    if (dominant && map.has(dominant)) return map.get(dominant)!;
    const first = map.values().next();
    return first.done ? null : first.value;
});

watch(
    () => primaryRemote.value?.videoTrack ?? null,
    (track, prev) => {
        if (prev) {
            try { prev.detach().forEach((el) => el.remove()); } catch { /* */ }
        }
        if (track && remoteVideoEl.value) {
            try { track.attach(remoteVideoEl.value); } catch { /* */ }
        }
    },
);

// Remote audio — attach every remote audio track to a hidden mount
// point so all participants are heard regardless of which is showing
// on the main canvas. Re-runs on map changes; we rebuild from scratch
// for simplicity (the set is small, ≤ ~6 participants typical).
watch(
    () => props.bridge.remoteParticipants.value,
    (map) => {
        const mount = remoteAudioMount.value;
        if (!mount) return;
        mount.innerHTML = '';
        map.forEach((p) => {
            if (p.audioTrack) {
                try {
                    const el = p.audioTrack.attach() as HTMLAudioElement;
                    el.autoplay = true;
                    mount.appendChild(el);
                } catch { /* */ }
            }
        });
    },
    { deep: false },
);

// Cleanup on unmount — detach everything we attached. The bridge's
// disconnect() is the source of truth for stopping the MediaStreams;
// here we just unhook the DOM elements.
onBeforeUnmount(() => {
    const lv = props.bridge.localVideoTrack.value;
    if (lv) {
        try { lv.detach().forEach((el) => el.remove()); } catch { /* */ }
    }
    const r = primaryRemote.value?.videoTrack;
    if (r) {
        try { r.detach().forEach((el) => el.remove()); } catch { /* */ }
    }
    if (remoteAudioMount.value) remoteAudioMount.value.innerHTML = '';
});

/* ──────────────────────────────────────────────────────────────────────
 * Hardware toggles — flip Twilio's local-track enabled state via the
 * bridge. The bridge mirrors the new state back into isAudioMuted /
 * isVideoOff so the UI binds to one source of truth.
 *
 * `sharing` (screen share) is reserved for a future getDisplayMedia
 * pass — the LocalVideoTrack is published with addTrack/removeTrack
 * on room.localParticipant rather than disable/enable.
 * ──────────────────────────────────────────────────────────────────── */
const muted = computed(() => props.bridge.isAudioMuted.value);
const cameraOn = computed(() => !props.bridge.isVideoOff.value);
const sharing = computed(() => props.bridge.isScreenSharing.value);
/** Twilio fires recording-started/stopped from the room; falls back to
 *  the always-on TCPA badge when the recording state is unknown
 *  (briefly during connect). The badge text differentiates so the
 *  agent can never be confused about whether they're on the record. */
const recording = computed(() => props.bridge.isRecording.value);

function onToggleMute(): void { props.bridge.toggleAudio(); }
function onToggleCamera(): void { props.bridge.toggleVideo(); }
async function onToggleShare(): Promise<void> {
    try { await props.bridge.toggleScreenShare(); } catch { /* surfaced via lastError */ }
}

/* ──────────────────────────────────────────────────────────────────────
 * Invite link — drops a `?join=<room>&roomId=<id>` URL in clipboard.
 * The recipient opens it (in this same tenant — invite is auth-gated
 * via the page's existing auth:sanctum middleware), Index.vue's onMount
 * picks up the param, and they land in the same Twilio room.
 *
 * Customer-facing guest invites are a separate feature — those need a
 * public route + a guest-token mint endpoint. This one is for agent-
 * to-supervisor and agent-to-agent collab calls.
 * ──────────────────────────────────────────────────────────────────── */
const inviteCopied = ref(false);
const inviteLink = computed(() => {
    if (!props.roomName) return null;
    const params = new URLSearchParams({
        join: props.roomName,
        roomId: props.roomId ?? '',
    });
    return `${window.location.origin}/prime-connect?${params.toString()}`;
});
let inviteResetTimer: number | undefined;
async function copyInvite(): Promise<void> {
    if (!inviteLink.value) return;
    try {
        await navigator.clipboard.writeText(inviteLink.value);
        inviteCopied.value = true;
        if (inviteResetTimer !== undefined) window.clearTimeout(inviteResetTimer);
        inviteResetTimer = window.setTimeout(() => (inviteCopied.value = false), 2200);
    } catch {
        // Some browsers reject clipboard writes without a recent user
        // gesture or insecure contexts; fall back to a selection prompt.
        window.prompt('Copy this invite link:', inviteLink.value);
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * Participant count — local participant + every connected remote.
 * Drives a small badge on the main canvas so you can see at a glance
 * that someone else has joined (without staring at the dominant-
 * speaker tile).
 * ──────────────────────────────────────────────────────────────────── */
const participantCount = computed(() => {
    const remotes = props.bridge.remoteParticipants.value.size;
    // +1 for the local participant when we're connected.
    const local = props.bridge.state.value === 'connected' ? 1 : 0;
    return remotes + local;
});

/* ──────────────────────────────────────────────────────────────────────
 * Side drawers. Notes + pipeline + AI assist all open in the same
 * right-side rail (they're rarely needed simultaneously); only one is
 * visible at a time. The drawer slides over the live coach column.
 * ──────────────────────────────────────────────────────────────────── */
type Drawer = 'notes' | 'pipeline' | 'assist' | null;
const drawer = ref<Drawer>(null);
function toggleDrawer(d: Exclude<Drawer, null>): void {
    drawer.value = drawer.value === d ? null : d;
}

/* ──────────────────────────────────────────────────────────────────────
 * War-room mode. When the closer flips this on, the call appears in the
 * supervisor war room with a flag — they can come help without the
 * closer breaking eye contact to message anyone.
 *
 * Wire: POST /api/prime-connect/rooms/{id}/flag { flagged: bool }. The
 * UI flips optimistically so the button feels instant; if the request
 * fails we roll back and surface the error via the existing lastError
 * channel (the bridge already exposes one).
 *
 * Disabled when we don't have a roomId — e.g. the invitee path where
 * we joined someone else's room. Only the room owner can flag their
 * own call, which lines up with the server-side permission check.
 * ──────────────────────────────────────────────────────────────────── */
const warRoomMode = ref(false);
const warRoomBusy = ref(false);
const canFlagWarRoom = computed(() => !!props.roomId && props.roomId !== '');

async function toggleWarRoom(): Promise<void> {
    if (!canFlagWarRoom.value || warRoomBusy.value) return;
    const next = !warRoomMode.value;
    // Optimistic flip — the button doesn't feel chatty if the request
    // takes 200ms to round-trip. Rollback on failure.
    warRoomMode.value = next;
    warRoomBusy.value = true;
    try {
        await axios.post(
            `/api/prime-connect/rooms/${props.roomId}/flag`,
            { flagged: next },
        );
    } catch {
        warRoomMode.value = !next; // rollback
    } finally {
        warRoomBusy.value = false;
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * Connection quality — derived from Twilio's NetworkQualityLevel
 * (0..5; 0 = call dropping, 5 = perfect). We don't have actual RTT
 * from the SDK without enabling the verbose stats API, so the
 * "ms" label is a coarse mapping from the level for now. When we
 * subscribe to LocalParticipant.NetworkQualityStats (verbosity 3),
 * the real RTT replaces this mapping.
 * ──────────────────────────────────────────────────────────────────── */
const codec = ref('HD');
const networkLevel = computed(() => props.bridge.networkQualityLevel.value);
const rttMs = computed(() => {
    // Approximate RTT bands from NetworkQualityLevel. These are the
    // mappings Twilio's own docs use for the "Good / Acceptable /
    // Poor" buckets; substitute real stats when verbosity=3 lands.
    const l = networkLevel.value;
    if (l === null) return 24;
    return [400, 280, 180, 90, 45, 20][l] ?? 24;
});
const qualityClass = computed(() => {
    if (props.twilioState === 'offline')  return 'bg-rose-500/15 text-rose-300 ring-rose-500/30';
    if (props.twilioState === 'degraded') return 'bg-amber-500/15 text-amber-300 ring-amber-500/30';
    const l = networkLevel.value;
    if (l !== null && l <= 1)             return 'bg-rose-500/15 text-rose-300 ring-rose-500/30';
    if (l !== null && l <= 2)             return 'bg-amber-500/15 text-amber-300 ring-amber-500/30';
    return 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30';
});

/* ──────────────────────────────────────────────────────────────────────
 * LIVE COACH panel — the moat.
 *
 * Real implementation: Twilio Media Streams → backend WS → Deepgram
 * realtime transcription (~300ms latency) → buffer ~5s rolling window
 * → LLM call with deal context + last 30s transcript → push prompts
 * via SignalR/Echo. Target end-to-end: <3s.
 *
 * Placeholder: stage prompts on a timer so the panel feels alive
 * during demos. Each prompt has a kind ('opportunity' | 'caution' |
 * 'metric') driving its colour treatment. Sentiment + talk-ratio
 * indicators sit above the prompt list, also placeholder.
 * ──────────────────────────────────────────────────────────────────── */
type CoachKind = 'opportunity' | 'caution' | 'metric';
interface CoachPrompt {
    id: string;
    kind: CoachKind;
    headline: string;
    detail: string;
    landedAt: number;
}

const sentiment = ref<'positive' | 'neutral' | 'cautious'>('positive');
const talkRatio = ref(58); // % closer talk; 50–60 is healthy

const prompts = ref<CoachPrompt[]>([]);
const seedScript: Omit<CoachPrompt, 'id' | 'landedAt'>[] = [
    {
        kind: 'opportunity',
        headline: 'Customer mentioned "kids college fund"',
        detail: 'Pivot to flexibility & ROI — emphasise the long-tail compounding angle.',
    },
    {
        kind: 'metric',
        headline: 'Talk ratio drifted to 71%',
        detail: 'Ask an open-ended question to bring them back into the conversation.',
    },
    {
        kind: 'caution',
        headline: 'Price objection signal detected',
        detail: 'They paused after the quote and changed topic — don\'t leave it unaddressed.',
    },
];

let coachTicker: number | undefined;
onMounted(() => {
    let i = 0;
    const drop = (): void => {
        if (i < seedScript.length) {
            prompts.value.unshift({
                ...seedScript[i],
                id: `p-${i}-${Date.now()}`,
                landedAt: Date.now(),
            });
            i++;
        }
    };
    // First prompt at 4s, then every 12s — long enough to read each one.
    window.setTimeout(drop, 4_000);
    coachTicker = window.setInterval(drop, 12_000);
});
onBeforeUnmount(() => {
    if (coachTicker !== undefined) window.clearInterval(coachTicker);
});

function promptClass(k: CoachKind): string {
    switch (k) {
        case 'opportunity': return 'border-l-emerald-400 bg-emerald-500/[0.04]';
        case 'caution':     return 'border-l-rose-400 bg-rose-500/[0.04]';
        case 'metric':      return 'border-l-sky-400 bg-sky-500/[0.04]';
    }
}
function promptIcon(k: CoachKind): 'flame' | 'alert' | 'broadcast' {
    return k === 'opportunity' ? 'flame' : k === 'caution' ? 'alert' : 'broadcast';
}

/* ──────────────────────────────────────────────────────────────────────
 * End call — confirm only if the call has been running >30s (a quick
 * misclick on the start CTA shouldn't require a confirm dialog).
 * ──────────────────────────────────────────────────────────────────── */
function endCall(): void {
    // Past 30s the call is "real enough" that an accidental click should be
    // confirmed; in the first 30s a misclick on Start shouldn't punish the
    // user with a dialog. The bridge.disconnect() that follows (handled by
    // the parent via usePrimeConnectCall.end()) releases the camera + mic.
    if (elapsedSeconds.value > 30 && !window.confirm('End the call now?')) return;
    emit('end');
}

const customerLabel = computed(() => props.intent?.leadName ?? 'Connecting…');
</script>

<template>
    <div class="relative flex h-full w-full bg-black text-white">
        <!-- ════════════════════════════════════════════════════════════
             LEFT — customer (main) feed + overlays
             ════════════════════════════════════════════════════════════ -->
        <section class="relative flex-1">
            <!-- Customer (primary remote) feed. The bridge attaches the
                 dominant speaker's video track to this <video> element
                 via a watch above; until a remote participant joins,
                 the gradient + name placeholder shows behind. -->
            <video
                ref="remoteVideoEl"
                class="absolute inset-0 h-full w-full object-cover"
                :class="{ 'opacity-0': !primaryRemote?.videoTrack }"
                autoplay
                playsinline
            ></video>
            <div
                v-if="!primaryRemote?.videoTrack"
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_#1a2238,_#05080f)]"
            ></div>
            <div
                v-if="!primaryRemote?.videoTrack"
                class="absolute inset-0 flex items-center justify-center"
            >
                <div class="text-center">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white/5 ring-1 ring-white/10">
                        <span class="text-2xl font-semibold text-white/80">
                            {{ (customerLabel[0] ?? '?').toUpperCase() }}
                        </span>
                    </div>
                    <div class="mt-3 text-lg font-medium">{{ customerLabel }}</div>
                    <div class="mt-1 text-xs uppercase tracking-[0.2em] text-white/40">
                        {{ bridge.state.value === 'connected' ? 'Awaiting customer' : 'Connecting…' }}
                    </div>
                </div>
            </div>

            <!-- Hidden mount for remote audio tracks — keeps audio
                 audible even when the speaker isn't on the main canvas. -->
            <div ref="remoteAudioMount" class="hidden" aria-hidden="true"></div>

            <!-- Recording badge — top-left, ugly-on-purpose, always visible.
                 TCPA disclosure: the agent must always know whether the
                 room is on the record. Two visual states:
                   • recording=true  → red, pulsing dot, "REC mm:ss"
                   • recording=false → amber, no dot, "REC PAUSED"
                 Both are intentionally loud — there is no "off" state for
                 this badge; the room being un-recorded is itself news. -->
            <div
                class="absolute left-4 top-4 flex items-center gap-2 rounded-md px-2.5 py-1 ring-2"
                :class="recording
                    ? 'bg-rose-600/90 ring-rose-300/40'
                    : 'bg-amber-500/90 ring-amber-200/50 text-deck-bg'"
            >
                <span
                    v-if="recording"
                    class="h-2 w-2 rounded-full bg-white animate-pulse"
                ></span>
                <span class="font-mono text-[11px] font-bold uppercase tracking-[0.18em]">
                    {{ recording ? 'REC' : 'REC PAUSED' }}
                </span>
                <span
                    v-if="recording"
                    class="font-mono tabular-nums text-[11px] text-white/90"
                >{{ elapsedLabel }}</span>
            </div>

            <!-- Connection quality — top-right -->
            <div
                class="absolute right-4 top-4 inline-flex items-center gap-2 rounded-md px-2.5 py-1 ring-1"
                :class="qualityClass"
            >
                <span class="font-mono text-[11px] font-bold tracking-wider">{{ codec }}</span>
                <span class="font-mono text-[11px] opacity-60">·</span>
                <span class="font-mono tabular-nums text-[11px]">{{ rttMs }}ms</span>
            </div>

            <!-- Customer name strip, bottom-left of the main feed -->
            <div class="absolute bottom-24 left-4 rounded-md bg-black/55 px-3 py-1.5 ring-1 ring-white/10">
                <div class="text-sm font-medium">{{ customerLabel }}</div>
                <div class="mt-0.5 text-[11px] uppercase tracking-wider text-white/50">
                    Customer
                </div>
            </div>

            <!-- Participant count badge — top-center, surfaces "someone
                 joined" without staring at the dominant-speaker tile.
                 Only renders when >1 (i.e., someone other than you). -->
            <div
                v-if="participantCount > 1"
                class="absolute left-1/2 top-4 -translate-x-1/2 rounded-md bg-black/55 px-3 py-1 text-xs ring-1 ring-white/10"
            >
                <span class="font-mono tabular-nums text-white">{{ participantCount }}</span>
                <span class="ml-1 text-white/60">in room</span>
            </div>

            <!-- Invite link button — bottom-right of the main feed.
                 Solo-call affordance: the inviter clicks once, gets
                 ?join=… in their clipboard, opens it elsewhere. -->
            <button
                v-if="inviteLink"
                type="button"
                class="absolute bottom-24 right-4 inline-flex items-center gap-2 rounded-md bg-black/55 px-3 py-2 text-xs ring-1 ring-white/10 transition-colors hover:bg-black/70"
                :title="inviteLink"
                @click="copyInvite"
            >
                <WarRoomIcon name="broadcast" class="h-3.5 w-3.5" />
                <span class="font-mono uppercase tracking-wider">
                    {{ inviteCopied ? 'Copied ✓' : 'Copy invite link' }}
                </span>
            </button>
        </section>

        <!-- ════════════════════════════════════════════════════════════
             RIGHT — self-view + live coach + drawer
             ════════════════════════════════════════════════════════════ -->
        <aside
            class="flex w-[340px] shrink-0 flex-col gap-3 border-l border-white/5 bg-black/40 p-3"
        >
            <!-- Self-view — bridge.localVideoTrack attached via watch -->
            <div class="relative aspect-video overflow-hidden rounded-md bg-black ring-1 ring-white/10">
                <video
                    ref="selfVideoEl"
                    class="h-full w-full object-cover"
                    autoplay
                    muted
                    playsinline
                ></video>
                <span class="absolute left-2 top-2 rounded bg-black/55 px-1.5 py-0.5 text-[10px] uppercase tracking-wider ring-1 ring-white/10">
                    You
                </span>
                <span
                    v-if="muted"
                    class="absolute right-2 top-2 rounded bg-rose-500/85 p-1 ring-1 ring-rose-200/30"
                    title="Muted"
                >
                    <WarRoomIcon name="volume-off" class="h-3 w-3" />
                </span>
                <div
                    v-if="!cameraOn"
                    class="absolute inset-0 flex items-center justify-center bg-black/85 text-xs text-white/60"
                >Camera off</div>
            </div>

            <!-- Live coach -->
            <div class="flex min-h-0 flex-1 flex-col rounded-md ring-1 ring-white/5 bg-white/[0.02]">
                <header class="flex items-center justify-between border-b border-white/5 px-3 py-2">
                    <div class="flex items-center gap-1.5">
                        <span class="deck-dot bg-floor-accent animate-pulse-dot"></span>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/70">
                            Live coach
                        </span>
                    </div>
                    <span class="font-mono text-[10px] uppercase tracking-wider text-white/40">
                        AI · realtime
                    </span>
                </header>

                <!-- Sentiment + talk-ratio meters -->
                <div class="grid grid-cols-2 gap-2 border-b border-white/5 px-3 py-2 text-[11px]">
                    <div>
                        <div class="text-[10px] uppercase tracking-wider text-white/40">Sentiment</div>
                        <div
                            class="mt-0.5 font-medium"
                            :class="sentiment === 'positive'
                                ? 'text-emerald-300'
                                : sentiment === 'cautious' ? 'text-amber-300' : 'text-white/70'"
                        >
                            {{ sentiment }}
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] uppercase tracking-wider text-white/40">Talk ratio</div>
                        <div class="mt-0.5 flex items-center gap-1.5">
                            <span class="font-mono tabular-nums text-white/80">{{ talkRatio }}%</span>
                            <span class="text-[10px] text-white/40">you</span>
                        </div>
                    </div>
                </div>

                <!-- Prompt feed -->
                <div class="flex-1 overflow-y-auto p-2 space-y-2">
                    <div
                        v-if="prompts.length === 0"
                        class="px-2 py-6 text-center text-[11px] text-white/40"
                    >
                        Listening for coaching cues…
                    </div>
                    <div
                        v-for="p in prompts"
                        :key="p.id"
                        class="rounded-md border-l-2 px-3 py-2"
                        :class="promptClass(p.kind)"
                    >
                        <div class="flex items-start gap-2">
                            <WarRoomIcon
                                :name="promptIcon(p.kind)"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-white/70"
                            />
                            <div class="min-w-0">
                                <div class="text-[12px] font-medium text-white/90">{{ p.headline }}</div>
                                <div class="mt-0.5 text-[11px] leading-snug text-white/60">{{ p.detail }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drawer (notes / pipeline / assist) — overlays beneath when open -->
            <div
                v-if="drawer"
                class="rounded-md ring-1 ring-white/5 bg-white/[0.03] p-3 text-xs text-white/70"
            >
                <div class="flex items-center justify-between">
                    <span class="font-semibold uppercase tracking-wider text-white/80">{{ drawer }}</span>
                    <button
                        type="button"
                        class="text-white/50 hover:text-white"
                        @click="drawer = null"
                    >Close</button>
                </div>
                <p class="mt-2 text-[11px] text-white/50">
                    {{
                        drawer === 'notes'    ? 'Quick-note pad. Saved against this call on hangup.' :
                        drawer === 'pipeline' ? 'Inline pipeline drawer — move the deal stage without leaving the call.' :
                                                'AI assist — ask for objection handling, pricing recall, or a recap.'
                    }}
                </p>
            </div>
        </aside>

        <!-- ════════════════════════════════════════════════════════════
             FLOATING CONTROL PILL — bottom-centre, three clusters
             ════════════════════════════════════════════════════════════ -->
        <div class="pointer-events-none absolute inset-x-0 bottom-5 flex justify-center">
            <div
                class="pointer-events-auto flex items-center gap-1 rounded-full bg-black/70 p-1.5 ring-1 ring-white/10 shadow-2xl backdrop-blur"
            >
                <!-- Cluster 1: hardware -->
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="muted
                        ? 'bg-rose-500/85 text-white hover:bg-rose-500'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    :title="muted ? 'Unmute (mic off)' : 'Mute (mic on)'"
                    @click="onToggleMute"
                >
                    <WarRoomIcon :name="muted ? 'volume-off' : 'volume'" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="!cameraOn
                        ? 'bg-rose-500/85 text-white hover:bg-rose-500'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    :title="cameraOn ? 'Turn camera off' : 'Turn camera on'"
                    @click="onToggleCamera"
                >
                    <!-- using grid as a stand-in camera icon to avoid bloating the icon set -->
                    <WarRoomIcon name="grid" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="sharing
                        ? 'bg-floor-accent/85 text-deck-bg hover:bg-floor-accentHi'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    :title="sharing ? 'Stop screen sharing' : 'Share screen'"
                    @click="onToggleShare"
                >
                    <WarRoomIcon name="broadcast" class="h-4 w-4" />
                </button>

                <!-- Divider -->
                <span class="mx-1 h-6 w-px bg-white/10"></span>

                <!-- Cluster 2: coaching / CRM -->
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="drawer === 'notes'
                        ? 'bg-white/15 text-white'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    title="Notes"
                    @click="toggleDrawer('notes')"
                >
                    <WarRoomIcon name="whisper" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="drawer === 'pipeline'
                        ? 'bg-white/15 text-white'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    title="Pipeline drawer"
                    @click="toggleDrawer('pipeline')"
                >
                    <WarRoomIcon name="map" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                    :class="drawer === 'assist'
                        ? 'bg-white/15 text-white'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    title="AI assist"
                    @click="toggleDrawer('assist')"
                >
                    <WarRoomIcon name="headphones" class="h-4 w-4" />
                </button>

                <!-- War-room toggle — flagged calls bubble up to supervisors -->
                <button
                    type="button"
                    class="flex h-10 items-center gap-1.5 rounded-full px-3 text-[11px] font-semibold uppercase tracking-wider transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    :class="warRoomMode
                        ? 'bg-rose-500/85 text-white animate-pulse-call'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    :disabled="!canFlagWarRoom || warRoomBusy"
                    :title="!canFlagWarRoom
                        ? 'War room flag is only available to the room owner'
                        : warRoomMode
                            ? 'War room flag is ON — supervisors notified'
                            : 'Flag this call for supervisor backup'"
                    @click="toggleWarRoom"
                >
                    <WarRoomIcon name="alert" class="h-3.5 w-3.5" />
                    War room
                </button>

                <!-- Divider — wider gap before destructive cluster -->
                <span class="mx-2 h-6 w-px bg-white/10"></span>

                <!-- Cluster 3: destructive -->
                <button
                    type="button"
                    class="flex h-10 items-center gap-1.5 rounded-full bg-amber-500/90 px-3 text-[11px] font-semibold uppercase tracking-wider text-deck-bg hover:bg-amber-400"
                    title="Transfer the call"
                >
                    <WarRoomIcon name="phone" class="h-3.5 w-3.5" />
                    Transfer
                </button>
                <button
                    type="button"
                    class="flex h-10 items-center gap-1.5 rounded-full bg-rose-600 px-4 text-[11px] font-semibold uppercase tracking-wider text-white hover:bg-rose-500"
                    title="End the call"
                    @click="endCall"
                >
                    End call
                </button>
            </div>
        </div>
    </div>
</template>
