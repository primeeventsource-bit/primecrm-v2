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
import WarRoomIcon from '@/Components/Supervisor/WarRoomIcon.vue';
import { useCallTimer } from '@/Composables/useCallTimer';

interface CallIntent {
    leadId: string | null;
    leadName: string | null;
    scheduledCallId: string | null;
}

const props = defineProps<{
    intent: CallIntent | null;
    twilioState: 'connected' | 'degraded' | 'offline';
}>();

const emit = defineEmits<{
    (e: 'end'): void;
}>();

/* ──────────────────────────────────────────────────────────────────────
 * Call timer.
 *
 * Wired to the existing useCallTimer composable: it watches an
 * `answered_at` ISO ref, ticks once per second while it's set, and
 * stops cleanly on unmount or clear. When useActiveCall is plugged in
 * for real Twilio data, swap `answeredAt` for the composable's
 * `call.value.answered_at` and the rest of this view's bindings keep
 * working unchanged.
 *
 * useCallTimer formats as MM:SS — fine for typical sales calls. For
 * the rare hour-plus call we override with a local h:mm:ss formatter
 * driven off elapsedSeconds rather than display.
 * ──────────────────────────────────────────────────────────────────── */
const answeredAt = ref<string | null>(new Date().toISOString());
const { elapsedSeconds } = useCallTimer(answeredAt);

const elapsedLabel = computed(() => {
    const s = elapsedSeconds.value;
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const pad = (n: number): string => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(r)}` : `${pad(m)}:${pad(r)}`;
});

/* ──────────────────────────────────────────────────────────────────────
 * Self-view. Same getUserMedia approach as the lobby's device check —
 * once Twilio is wired this stream will come from
 * room.localParticipant instead, but the <video> element stays.
 * ──────────────────────────────────────────────────────────────────── */
const selfVideo = ref<HTMLVideoElement | null>(null);
let selfStream: MediaStream | null = null;
async function startSelfPreview(): Promise<void> {
    try {
        selfStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        if (selfVideo.value) {
            selfVideo.value.srcObject = selfStream;
            await selfVideo.value.play().catch(() => { /* autoplay blocked is OK */ });
        }
    } catch { /* permission denied — placeholder block stays visible */ }
}
function stopSelfPreview(): void {
    selfStream?.getTracks().forEach(t => t.stop());
    selfStream = null;
}
onMounted(startSelfPreview);
onBeforeUnmount(stopSelfPreview);

/* ──────────────────────────────────────────────────────────────────────
 * Hardware toggles. Placeholder state — final implementation flips
 * room.localParticipant.audioTracks[0].disable() / enable().
 * ──────────────────────────────────────────────────────────────────── */
const muted    = ref(false);
const cameraOn = ref(true);
const sharing  = ref(false);

watch(muted, (m) => {
    selfStream?.getAudioTracks().forEach(t => { t.enabled = !m; });
});
watch(cameraOn, (on) => {
    selfStream?.getVideoTracks().forEach(t => { t.enabled = on; });
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
 * closer breaking eye contact to message anyone. Placeholder; the wire
 * is `axios.post('/api/calls/{id}/flag')`.
 * ──────────────────────────────────────────────────────────────────── */
const warRoomMode = ref(false);

/* ──────────────────────────────────────────────────────────────────────
 * Connection quality. Same idea as the lobby pill but richer — codec
 * + ping. Placeholder values until twilio-video's NetworkQualityLevel
 * is bound.
 * ──────────────────────────────────────────────────────────────────── */
const codec = ref('HD');
const rttMs = ref(24);
const qualityClass = computed(() => {
    if (props.twilioState === 'offline')  return 'bg-rose-500/15 text-rose-300 ring-rose-500/30';
    if (props.twilioState === 'degraded') return 'bg-amber-500/15 text-amber-300 ring-amber-500/30';
    if (rttMs.value >= 150)               return 'bg-amber-500/15 text-amber-300 ring-amber-500/30';
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
    // user with a dialog.
    //
    // Wire-up note: when useActiveCall is connected, replace `emit('end')`
    // with `await activeCall.endCall()` so the backend POST /api/calls/{id}/end
    // happens before this view tears down. The endCall composable returns
    // the canonical Call record we then store as the just-ended row.
    if (elapsedSeconds.value > 30 && !window.confirm('End the call now?')) return;
    answeredAt.value = null; // stops the timer
    stopSelfPreview();
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
            <!--
              Customer placeholder feed. In production this is a <video>
              with srcObject = remoteParticipant.videoTracks[0]. Until
              then we render an animated gradient backdrop with the
              customer name centred, so the layout is real.
            -->
            <div
                class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_#1a2238,_#05080f)]"
            ></div>
            <div class="absolute inset-0 flex items-center justify-center">
                <div class="text-center">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white/5 ring-1 ring-white/10">
                        <span class="text-2xl font-semibold text-white/80">
                            {{ (customerLabel[0] ?? '?').toUpperCase() }}
                        </span>
                    </div>
                    <div class="mt-3 text-lg font-medium">{{ customerLabel }}</div>
                    <div class="mt-1 text-xs uppercase tracking-[0.2em] text-white/40">
                        Awaiting video stream
                    </div>
                </div>
            </div>

            <!-- Recording badge — top-left, ugly-on-purpose, always visible -->
            <div class="absolute left-4 top-4 flex items-center gap-2 rounded-md bg-rose-600/90 px-2.5 py-1 ring-2 ring-rose-300/40">
                <span class="h-2 w-2 rounded-full bg-white animate-pulse"></span>
                <span class="font-mono text-[11px] font-bold uppercase tracking-[0.18em]">REC</span>
                <span class="font-mono tabular-nums text-[11px] text-white/90">{{ elapsedLabel }}</span>
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
        </section>

        <!-- ════════════════════════════════════════════════════════════
             RIGHT — self-view + live coach + drawer
             ════════════════════════════════════════════════════════════ -->
        <aside
            class="flex w-[340px] shrink-0 flex-col gap-3 border-l border-white/5 bg-black/40 p-3"
        >
            <!-- Self-view -->
            <div class="relative aspect-video overflow-hidden rounded-md bg-black ring-1 ring-white/10">
                <video
                    ref="selfVideo"
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
                    @click="muted = !muted"
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
                    @click="cameraOn = !cameraOn"
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
                    :title="sharing ? 'Stop sharing' : 'Share screen'"
                    @click="sharing = !sharing"
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
                    class="flex h-10 items-center gap-1.5 rounded-full px-3 text-[11px] font-semibold uppercase tracking-wider transition-colors"
                    :class="warRoomMode
                        ? 'bg-rose-500/85 text-white animate-pulse-call'
                        : 'bg-white/5 text-white/80 hover:bg-white/15'"
                    :title="warRoomMode
                        ? 'War room flag is ON — supervisors notified'
                        : 'Flag this call for supervisor backup'"
                    @click="warRoomMode = !warRoomMode"
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
