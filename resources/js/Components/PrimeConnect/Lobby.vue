<script setup lang="ts">
/**
 * Prime Connect — lobby view.
 *
 * Two-column layout:
 *
 *   LEFT  Device check + quick start. Live camera preview, mic level
 *         meter (WebAudio), and source dropdowns for camera/mic/speaker
 *         using the standard navigator.mediaDevices.enumerateDevices
 *         API. The primary CTA is "Start instant call"; secondary
 *         actions are Schedule and Invite Link. A small network
 *         diagnostic strip at the bottom shows the Twilio region and
 *         ping — if those go bad the user knows before they click.
 *
 *   RIGHT Active sessions + up next. Mirrors the supervisor war room's
 *         tile aesthetic so the page is useful even when the user
 *         isn't dialing themselves. The selected lobby tab (Active /
 *         Scheduled / History / Recordings) controls which list
 *         renders here; the device-check column on the left stays put
 *         across all tabs so the user can verify devices at any time.
 *
 * No Twilio yet — the tracks come from getUserMedia and the session /
 * schedule lists are placeholder until the CallCenter API has the
 * shape we need. Where the wire-up will land is called out inline.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { avatarTone, initials, moneyShort, shortClock, shortDuration } from '@/Components/Supervisor/helpers';
import WarRoomIcon from '@/Components/Supervisor/WarRoomIcon.vue';
import { usePrimeConnectRooms } from '@/Composables/usePrimeConnectRooms';

type LobbyTab = 'active' | 'scheduled' | 'history' | 'recordings';

const props = defineProps<{
    tab: LobbyTab;
    nowMs: number;
    /** Tenant id from the page shell — drives the Echo channel for room updates. */
    tenantId: string;
}>();

interface CallIntent {
    leadId: string | null;
    leadName: string | null;
    scheduledCallId: string | null;
}

interface JoinIntent {
    roomName: string;
    roomId: string;
}

const emit = defineEmits<{
    (e: 'start', intent: CallIntent): void;
    (e: 'join', intent: JoinIntent): void;
    (e: 'counts', counts: Record<LobbyTab, number>): void;
}>();

/* ══════════════════════════════════════════════════════════════════════
 * DEVICE CHECK — camera preview + mic level + source pickers
 *
 * All client-side: navigator.mediaDevices for enumeration and a
 * one-time getUserMedia call to drive the preview <video> + the
 * AnalyserNode for the mic level meter. Re-acquired when the user
 * switches camera or mic dropdown so the preview reflects the actual
 * device that will be used on the call.
 * ══════════════════════════════════════════════════════════════════════ */
interface DeviceOption { id: string; label: string; }

const cameras  = ref<DeviceOption[]>([]);
const mics     = ref<DeviceOption[]>([]);
const speakers = ref<DeviceOption[]>([]);

const selectedCamera  = ref<string>('');
const selectedMic     = ref<string>('');
const selectedSpeaker = ref<string>('');

const previewVideo = ref<HTMLVideoElement | null>(null);
const previewStream = ref<MediaStream | null>(null);
const previewError  = ref<string | null>(null);
const previewReady  = ref(false);

/** 0–100; smoothed RMS of the live mic input. Drives the level meter. */
const micLevel = ref(0);

let audioCtx: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let levelRaf: number | undefined;

async function listDevices(): Promise<void> {
    try {
        const all = await navigator.mediaDevices.enumerateDevices();
        cameras.value  = all.filter(d => d.kind === 'videoinput')
                            .map(d => ({ id: d.deviceId, label: d.label || 'Camera' }));
        mics.value     = all.filter(d => d.kind === 'audioinput')
                            .map(d => ({ id: d.deviceId, label: d.label || 'Microphone' }));
        speakers.value = all.filter(d => d.kind === 'audiooutput')
                            .map(d => ({ id: d.deviceId, label: d.label || 'Speaker' }));
        if (!selectedCamera.value && cameras.value[0])  selectedCamera.value  = cameras.value[0].id;
        if (!selectedMic.value && mics.value[0])        selectedMic.value     = mics.value[0].id;
        if (!selectedSpeaker.value && speakers.value[0]) selectedSpeaker.value = speakers.value[0].id;
    } catch {
        // Enumeration failures are silent — the dropdowns just stay empty.
    }
}

function stopPreview(): void {
    previewStream.value?.getTracks().forEach(t => t.stop());
    previewStream.value = null;
    previewReady.value = false;
    if (levelRaf !== undefined) { cancelAnimationFrame(levelRaf); levelRaf = undefined; }
    analyser?.disconnect(); analyser = null;
    audioCtx?.close().catch(() => { /* already closed */ }); audioCtx = null;
    micLevel.value = 0;
}

async function startPreview(): Promise<void> {
    stopPreview();
    previewError.value = null;
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: selectedCamera.value
                ? { deviceId: { exact: selectedCamera.value } }
                : true,
            audio: selectedMic.value
                ? { deviceId: { exact: selectedMic.value } }
                : true,
        });
        previewStream.value = stream;
        if (previewVideo.value) {
            previewVideo.value.srcObject = stream;
            await previewVideo.value.play().catch(() => { /* autoplay blocked is OK */ });
        }
        previewReady.value = true;

        // After permission is granted, device labels become visible — refresh.
        await listDevices();

        // Mic level meter — RMS over a small FFT, smoothed for visual calm.
        const Ctor = window.AudioContext
            ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
        if (Ctor && stream.getAudioTracks().length > 0) {
            audioCtx = new Ctor();
            const source = audioCtx.createMediaStreamSource(stream);
            analyser = audioCtx.createAnalyser();
            analyser.fftSize = 512;
            source.connect(analyser);
            const buf = new Uint8Array(analyser.frequencyBinCount);
            const step = (): void => {
                if (!analyser) return;
                analyser.getByteTimeDomainData(buf);
                let sumSq = 0;
                for (let i = 0; i < buf.length; i++) {
                    const v = (buf[i] - 128) / 128;
                    sumSq += v * v;
                }
                const rms = Math.sqrt(sumSq / buf.length);
                // Smooth toward the new value — feels less jittery.
                const target = Math.min(100, Math.round(rms * 240));
                micLevel.value = micLevel.value + (target - micLevel.value) * 0.4;
                levelRaf = requestAnimationFrame(step);
            };
            step();
        }
    } catch (err) {
        previewError.value = err instanceof Error
            ? err.message
            : 'Camera/mic permission denied';
    }
}

onMounted(async () => {
    // Try to enumerate without permission first (some browsers return
    // labels-only-after-permission, that's fine — we'll re-list once
    // getUserMedia succeeds).
    await listDevices();
    await startPreview();
});
onBeforeUnmount(stopPreview);

// Re-acquire the stream when the user picks a different camera/mic.
watch([selectedCamera, selectedMic], () => { void startPreview(); });

const micBars = computed<number[]>(() => {
    // 12 bars across the meter, lit proportionally to the level.
    const lit = Math.round((micLevel.value / 100) * 12);
    return Array.from({ length: 12 }, (_, i) => i < lit ? 1 : 0);
});

/* ══════════════════════════════════════════════════════════════════════
 * NETWORK DIAGNOSTIC — Twilio region + ping
 *
 * Placeholder figures until the Twilio Network Quality API is wired.
 * In production this comes from twilio-video's NetworkQualityLevel
 * pre-flight test (twilio-video runPreflight) which gives RTT, jitter,
 * and a 0–5 quality score. We only show region + ping in the lobby —
 * the active call has a richer indicator top-right.
 * ──────────────────────────────────────────────────────────────────── */
const twilioRegion = ref('us1');
const twilioPingMs = ref(24);

/* ══════════════════════════════════════════════════════════════════════
 * RIGHT COLUMN — list data
 *
 * Placeholder records. Wire-up plan (concrete next-pass steps):
 *
 *   activeSessions ← useSupervisorChannel(tenantId).liveCalls
 *                    (or useEcho().on('tenant.{id}.calls', '.call.*', …)
 *                     for an agent-scoped feed). LiveCall already has
 *                     call_id / customer_name / started_at / hot, which
 *                     map cleanly onto the ActiveSession shape below.
 *   scheduled      ← axios.get('/api/calls/scheduled')           (TBD)
 *   history        ← axios.get('/api/calls?agent_id=…&live=false')
 *   recordings     ← axios.get('/api/calls/recordings')          (TBD)
 *
 * The shape of each placeholder record mirrors the existing /api/calls
 * payload (Call type in resources/js/types/api) so substitution is a
 * one-liner per list.
 * ══════════════════════════════════════════════════════════════════════ */
interface ActiveSession {
    id: string;
    leadId: string;
    leadName: string;
    agentName: string;
    startedAt: string;
    flagged: boolean;          // war-room mode toggled on by the closer
    /** Twilio room name — required for the Join button's invite path. */
    roomName: string;
}
interface ScheduledCall {
    id: string;
    leadId: string;
    leadName: string;
    company: string | null;
    startsAt: string;
    dealValue: number | null;
}
interface HistoryRow {
    id: string;
    leadName: string;
    endedAt: string;
    durationSec: number;
    disposition: 'closed_won' | 'callback' | 'no_answer' | 'closed_lost';
    revenue: number | null;
    hasRecording: boolean;
}
interface RecordingRow {
    id: string;
    leadName: string;
    endedAt: string;
    durationSec: number;
    sizeMb: number;
}

// Active sessions are now backed by /api/prime-connect/rooms with
// Echo updates on the supervisor channel (room.created / room.ended).
// The composable handles initial fetch + live append/drop; we map the
// API shape into the ActiveSession the template expects so the
// template changes stay surgical.
const { rooms: liveRooms } = usePrimeConnectRooms(props.tenantId, { activeOnly: true });

const activeSessions = computed<ActiveSession[]>(() =>
    liveRooms.value.map((r) => ({
        id: r.id,
        leadId: r.lead_id ?? r.id,
        leadName: r.lead_name ?? 'Unknown lead',
        agentName: r.agent_name ?? 'Agent',
        startedAt: r.initiated_at ?? r.created_at ?? new Date().toISOString(),
        flagged: r.flagged,
        // Carry the room name forward for the Join button — the
        // ActiveSession type was extended below for this.
        roomName: r.room_name ?? '',
    })),
);

function joinSession(s: ActiveSession): void {
    if (! s.roomName) return;
    emit('join', { roomName: s.roomName, roomId: s.id });
}

const scheduled = ref<ScheduledCall[]>([
    {
        id: 'sch-1',
        leadId: 'lead-99c',
        leadName: 'Priya Shah',
        company: 'Northwind Logistics',
        startsAt: new Date(Date.now() + 14 * 60 * 1000).toISOString(),
        dealValue: 18_500,
    },
    {
        id: 'sch-2',
        leadId: 'lead-12d',
        leadName: 'Tom Blackwell',
        company: null,
        startsAt: new Date(Date.now() + 47 * 60 * 1000).toISOString(),
        dealValue: 4_200,
    },
]);

const history = ref<HistoryRow[]>([
    {
        id: 'h-1', leadName: 'Eli Whitman',
        endedAt: new Date(Date.now() - 32 * 60 * 1000).toISOString(),
        durationSec: 18 * 60 + 14, disposition: 'closed_won',
        revenue: 24_900, hasRecording: true,
    },
    {
        id: 'h-2', leadName: 'Sarah Lin',
        endedAt: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
        durationSec: 6 * 60 + 41, disposition: 'callback',
        revenue: null, hasRecording: true,
    },
    {
        id: 'h-3', leadName: 'Ben Markov',
        endedAt: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
        durationSec: 0, disposition: 'no_answer',
        revenue: null, hasRecording: false,
    },
]);

const recordings = ref<RecordingRow[]>([
    { id: 'r-1', leadName: 'Eli Whitman',
      endedAt: new Date(Date.now() - 32 * 60 * 1000).toISOString(),
      durationSec: 18 * 60 + 14, sizeMb: 28.4 },
    { id: 'r-2', leadName: 'Sarah Lin',
      endedAt: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
      durationSec: 6 * 60 + 41, sizeMb: 10.2 },
]);

// Push counts up to the page shell so the tab row can decorate badges.
watch(
    [activeSessions, scheduled, history, recordings],
    () => {
        emit('counts', {
            active:     activeSessions.value.length,
            scheduled:  scheduled.value.length,
            history:    history.value.length,
            recordings: recordings.value.length,
        });
    },
    { immediate: true, deep: true },
);

/* ══════════════════════════════════════════════════════════════════════
 * ACTIONS
 * ══════════════════════════════════════════════════════════════════════ */
function startInstant(): void {
    emit('start', { leadId: null, leadName: null, scheduledCallId: null });
}
function startScheduled(s: ScheduledCall): void {
    emit('start', { leadId: s.leadId, leadName: s.leadName, scheduledCallId: s.id });
}
function copyInviteLink(): void {
    const link = `${window.location.origin}/prime-connect/join/${crypto.randomUUID()}`;
    navigator.clipboard?.writeText(link).catch(() => { /* clipboard unavailable */ });
}
function dispositionPill(d: HistoryRow['disposition']): string {
    switch (d) {
        case 'closed_won':  return 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30';
        case 'closed_lost': return 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30';
        case 'callback':    return 'bg-sky-500/15 text-sky-300 ring-1 ring-sky-500/30';
        case 'no_answer':   return 'bg-deck-muted text-deck-soft ring-1 ring-deck-line';
    }
}
function durationLabel(sec: number): string {
    if (sec === 0) return '—';
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

/** "in 14m" / "in 1h 02m" — used in the next-up panel. */
function relativeFuture(iso: string): string {
    const diffSec = Math.max(0, Math.floor((Date.parse(iso) - props.nowMs) / 1000));
    if (diffSec < 60) return `in ${diffSec}s`;
    const m = Math.floor(diffSec / 60);
    if (m < 60) return `in ${m}m`;
    const h = Math.floor(m / 60);
    const rm = m - h * 60;
    return rm > 0 ? `in ${h}h ${String(rm).padStart(2, '0')}m` : `in ${h}h`;
}
</script>

<template>
    <!--
      Lobby grid. The device-check column stays the same width regardless
      of which lobby tab the user is on; the right column re-renders with
      the matching list. minmax(380px,…) keeps the device check usable on
      narrow viewports without letting it dominate on wide ones.
    -->
    <div
        class="grid h-full gap-4 p-6"
        style="grid-template-columns: minmax(380px, 1fr) minmax(0, 1.2fr);"
    >
        <!-- ════════════════════════════════════════════════════════════
             LEFT — Device check + quick start
             ════════════════════════════════════════════════════════════ -->
        <section class="deck-card flex flex-col overflow-hidden">
            <header class="border-b border-deck-line px-4 py-3">
                <div class="deck-label">Device check</div>
                <div class="mt-0.5 text-sm text-deck-text">
                    Verify camera & mic before the call
                </div>
            </header>

            <!-- Camera preview -->
            <div class="relative aspect-video bg-deck-bg">
                <video
                    ref="previewVideo"
                    class="h-full w-full object-cover"
                    autoplay
                    muted
                    playsinline
                ></video>
                <div
                    v-if="!previewReady"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-deck-bg text-deck-soft"
                >
                    <div class="text-sm">{{ previewError ?? 'Waiting for camera…' }}</div>
                    <button
                        v-if="previewError"
                        type="button"
                        class="btn-ghost text-xs"
                        @click="startPreview"
                    >Try again</button>
                </div>
                <!-- Self-label so the user understands what they're seeing -->
                <span
                    v-if="previewReady"
                    class="absolute left-2 top-2 pill bg-black/50 text-deck-text ring-1 ring-white/10"
                >
                    <span class="deck-dot bg-floor-info mr-1.5"></span>
                    Preview · you
                </span>
            </div>

            <!-- Mic level meter -->
            <div class="flex items-center gap-3 border-t border-deck-line px-4 py-3">
                <span class="deck-label shrink-0">Mic</span>
                <div class="flex flex-1 items-center gap-0.5">
                    <span
                        v-for="(lit, i) in micBars"
                        :key="i"
                        class="h-3 w-1.5 rounded-sm transition-colors"
                        :class="lit
                            ? (i >= 9 ? 'bg-rose-400' : i >= 6 ? 'bg-amber-400' : 'bg-emerald-400')
                            : 'bg-deck-muted'"
                    ></span>
                </div>
                <span class="font-mono tabular-nums text-[11px] text-deck-dim w-8 text-right">
                    {{ Math.round(micLevel) }}
                </span>
            </div>

            <!-- Source pickers -->
            <div class="grid grid-cols-1 gap-3 border-t border-deck-line p-4">
                <label class="block">
                    <span class="deck-label">Camera</span>
                    <select v-model="selectedCamera" class="input mt-1">
                        <option v-if="cameras.length === 0" :value="''">No cameras detected</option>
                        <option v-for="c in cameras" :key="c.id" :value="c.id">{{ c.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="deck-label">Microphone</span>
                    <select v-model="selectedMic" class="input mt-1">
                        <option v-if="mics.length === 0" :value="''">No microphones detected</option>
                        <option v-for="m in mics" :key="m.id" :value="m.id">{{ m.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="deck-label">Speaker</span>
                    <select v-model="selectedSpeaker" class="input mt-1">
                        <option v-if="speakers.length === 0" :value="''">System default</option>
                        <option v-for="s in speakers" :key="s.id" :value="s.id">{{ s.label }}</option>
                    </select>
                </label>
            </div>

            <!-- CTA row: primary + two secondaries -->
            <div class="flex items-center gap-2 border-t border-deck-line p-4">
                <button
                    type="button"
                    class="btn-primary flex-1"
                    @click="startInstant"
                >
                    Start instant call
                </button>
                <button
                    type="button"
                    class="btn-ghost"
                    title="Schedule a call for later"
                >
                    Schedule
                </button>
                <button
                    type="button"
                    class="btn-ghost"
                    title="Copy a join link to share with a customer"
                    @click="copyInviteLink"
                >
                    Invite link
                </button>
            </div>

            <!-- Network diagnostic strip — small, always visible -->
            <div class="mt-auto flex items-center justify-between border-t border-deck-line bg-deck-bg/40 px-4 py-2 text-[11px]">
                <span class="flex items-center gap-1.5 text-deck-dim">
                    <span class="deck-dot bg-emerald-400"></span>
                    <span class="font-mono uppercase tracking-wider">Twilio · {{ twilioRegion }}</span>
                </span>
                <span class="font-mono tabular-nums text-deck-soft">{{ twilioPingMs }}ms</span>
            </div>
        </section>

        <!-- ════════════════════════════════════════════════════════════
             RIGHT — list panel, switches by tab
             ════════════════════════════════════════════════════════════ -->
        <section class="flex flex-col gap-4 overflow-hidden">
            <!-- ACTIVE — live sessions + next up -->
            <template v-if="tab === 'active'">
                <div class="deck-card flex flex-col overflow-hidden">
                    <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                        <div>
                            <div class="deck-label">Active sessions</div>
                            <div class="mt-0.5 text-sm text-deck-text">
                                Live calls happening on the floor right now
                            </div>
                        </div>
                        <span class="font-mono tabular-nums text-deck-soft">
                            {{ activeSessions.length }}
                        </span>
                    </header>

                    <div v-if="activeSessions.length === 0" class="px-4 py-6 text-center text-sm text-deck-dim">
                        No calls in progress.
                    </div>
                    <ul v-else class="divide-y divide-deck-line">
                        <li
                            v-for="s in activeSessions"
                            :key="s.id"
                            class="flex items-center gap-3 px-4 py-3 hover:bg-deck-raised/40"
                        >
                            <!-- Stable avatar derived from lead id -->
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                :class="avatarTone(s.leadId)"
                            >
                                {{ initials(s.leadName, s.leadId) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-medium text-deck-text">
                                        {{ s.leadName }}
                                    </span>
                                    <span
                                        v-if="s.flagged"
                                        class="pill bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30"
                                        title="Closer toggled war room mode — supervisor attention requested"
                                    >
                                        <WarRoomIcon name="alert" class="h-2.5 w-2.5 mr-1" /> war room
                                    </span>
                                </div>
                                <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-deck-dim">
                                    <span class="deck-dot bg-sky-400 animate-pulse"></span>
                                    <span>with {{ s.agentName }}</span>
                                    <span class="font-mono">·</span>
                                    <span class="font-mono tabular-nums">
                                        {{ shortDuration(s.startedAt, nowMs) }}
                                    </span>
                                </div>
                            </div>
                            <!-- Row actions. Listen/Whisper are supervisor
                                 affordances kept for visual parity with
                                 the war room (wire-up TBD). Join is the
                                 working action — drops the user into the
                                 same Twilio room as the agent. -->
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-sky-300 ring-1 ring-sky-500/30 hover:bg-sky-500/10"
                                    title="Listen silently (supervisor)"
                                >
                                    <WarRoomIcon name="headphones" class="h-3.5 w-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-amber-300 ring-1 ring-amber-500/30 hover:bg-amber-500/10"
                                    title="Whisper to agent (supervisor)"
                                >
                                    <WarRoomIcon name="whisper" class="h-3.5 w-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="btn-primary text-xs"
                                    :disabled="!s.roomName"
                                    :title="s.roomName ? 'Join this room' : 'Room not ready'"
                                    @click="joinSession(s)"
                                >
                                    Join
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Up next -->
                <div class="deck-card flex flex-col overflow-hidden">
                    <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                        <div>
                            <div class="deck-label">Up next</div>
                            <div class="mt-0.5 text-sm text-deck-text">Your next scheduled calls</div>
                        </div>
                    </header>
                    <ul class="divide-y divide-deck-line">
                        <li
                            v-for="s in scheduled.slice(0, 3)"
                            :key="s.id"
                            class="flex items-center gap-3 px-4 py-3"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                :class="avatarTone(s.leadId)"
                            >
                                {{ initials(s.leadName, s.leadId) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-deck-text">
                                    {{ s.leadName }}
                                    <span v-if="s.company" class="text-deck-dim font-normal">· {{ s.company }}</span>
                                </div>
                                <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-deck-dim">
                                    <span class="font-mono tabular-nums text-deck-soft">{{ shortClock(s.startsAt) }}</span>
                                    <span class="font-mono">·</span>
                                    <span>{{ relativeFuture(s.startsAt) }}</span>
                                    <span v-if="s.dealValue" class="font-mono">·</span>
                                    <span v-if="s.dealValue" class="font-mono tabular-nums text-emerald-300">
                                        {{ moneyShort(s.dealValue) }}
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="btn-ghost text-xs"
                                title="Pre-load deal context before the call"
                            >Prep</button>
                            <button
                                type="button"
                                class="btn-primary text-xs"
                                @click="startScheduled(s)"
                            >Join</button>
                        </li>
                    </ul>
                </div>
            </template>

            <!-- SCHEDULED — full schedule list -->
            <div v-else-if="tab === 'scheduled'" class="deck-card flex flex-col overflow-hidden">
                <header class="border-b border-deck-line px-4 py-3">
                    <div class="deck-label">Scheduled</div>
                    <div class="mt-0.5 text-sm text-deck-text">
                        Calls on the books · prep before they ring
                    </div>
                </header>
                <ul class="divide-y divide-deck-line">
                    <li
                        v-for="s in scheduled"
                        :key="s.id"
                        class="grid grid-cols-[auto_1fr_auto_auto] items-center gap-3 px-4 py-3"
                    >
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-full text-xs font-semibold"
                            :class="avatarTone(s.leadId)"
                        >{{ initials(s.leadName, s.leadId) }}</div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-deck-text">
                                {{ s.leadName }}
                                <span v-if="s.company" class="text-deck-dim font-normal">· {{ s.company }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-deck-dim">
                                <span class="font-mono tabular-nums text-deck-soft">{{ shortClock(s.startsAt) }}</span>
                                <span class="font-mono">·</span>
                                <span>{{ relativeFuture(s.startsAt) }}</span>
                            </div>
                        </div>
                        <span
                            v-if="s.dealValue"
                            class="font-mono tabular-nums text-sm text-emerald-300"
                        >{{ moneyShort(s.dealValue) }}</span>
                        <span v-else></span>
                        <button
                            type="button"
                            class="btn-ghost text-xs"
                            @click="startScheduled(s)"
                        >Join</button>
                    </li>
                </ul>
            </div>

            <!-- HISTORY -->
            <div v-else-if="tab === 'history'" class="deck-card flex flex-col overflow-hidden">
                <header class="border-b border-deck-line px-4 py-3">
                    <div class="deck-label">History</div>
                    <div class="mt-0.5 text-sm text-deck-text">
                        Calls you've taken · review dispositions and recordings
                    </div>
                </header>
                <ul class="divide-y divide-deck-line">
                    <li
                        v-for="h in history"
                        :key="h.id"
                        class="grid grid-cols-[1fr_auto_auto_auto_auto] items-center gap-3 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-deck-text">{{ h.leadName }}</div>
                            <div class="mt-0.5 text-[11px] text-deck-dim">
                                <span class="font-mono tabular-nums">{{ shortClock(h.endedAt) }}</span>
                            </div>
                        </div>
                        <span class="font-mono tabular-nums text-sm text-deck-soft">
                            {{ durationLabel(h.durationSec) }}
                        </span>
                        <span class="pill" :class="dispositionPill(h.disposition)">
                            {{ h.disposition.replace(/_/g, ' ') }}
                        </span>
                        <span
                            class="font-mono tabular-nums text-sm"
                            :class="h.revenue ? 'text-emerald-300' : 'text-deck-dim'"
                        >{{ h.revenue ? moneyShort(h.revenue) : '—' }}</span>
                        <button
                            type="button"
                            class="btn-ghost text-xs"
                            :disabled="!h.hasRecording"
                            :title="h.hasRecording ? 'Play recording' : 'No recording captured'"
                        >Play</button>
                    </li>
                </ul>
            </div>

            <!-- RECORDINGS -->
            <div v-else-if="tab === 'recordings'" class="deck-card flex flex-col overflow-hidden">
                <header class="border-b border-deck-line px-4 py-3">
                    <div class="deck-label">Recordings</div>
                    <div class="mt-0.5 text-sm text-deck-text">
                        Composed clips · post-call review for the floor
                    </div>
                </header>
                <ul class="divide-y divide-deck-line">
                    <li
                        v-for="r in recordings"
                        :key="r.id"
                        class="grid grid-cols-[1fr_auto_auto_auto] items-center gap-3 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-deck-text">{{ r.leadName }}</div>
                            <div class="mt-0.5 text-[11px] text-deck-dim">
                                <span class="font-mono tabular-nums">{{ shortClock(r.endedAt) }}</span>
                            </div>
                        </div>
                        <span class="font-mono tabular-nums text-sm text-deck-soft">
                            {{ durationLabel(r.durationSec) }}
                        </span>
                        <span class="font-mono tabular-nums text-sm text-deck-dim">
                            {{ r.sizeMb.toFixed(1) }}MB
                        </span>
                        <button type="button" class="btn-ghost text-xs">Play</button>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
