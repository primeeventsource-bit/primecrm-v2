<script setup lang="ts">
/**
 * Prime Connect — public customer guest page.
 *
 * This is the screen a customer lands on when an agent shares an invite
 * link. It's deliberately stripped-down compared to the staff
 * /prime-connect view:
 *
 *   - No AppLayout. The customer isn't a CRM user; the sidebar would
 *     just confuse them and leak surface area.
 *   - No Live Coach / pipeline / war room. Those are agent affordances
 *     that don't belong on a customer screen.
 *   - No "End the call" confirm dialog beyond 30s — customers should be
 *     able to leave at any time without friction.
 *
 * Flow:
 *   1. Page mounts with the URL token (passed in as a prop by Inertia).
 *   2. GET /api/prime-connect/guest/{token} validates + returns room info.
 *      Invalid / expired tokens land on the friendly "expired link" state.
 *   3. Customer clicks "Join the call" → Twilio JWT mint via
 *      POST /api/prime-connect/guest/{token}/access-token → bridge
 *      connect with that token (room name pinned by the JWT grant).
 *   4. Same in-call <video> elements as the staff side; the customer is
 *     just another participant in the same Twilio room.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useTwilioBridge } from '@/Composables/useTwilioBridge';

const props = defineProps<{
    token: string;
}>();

/* ──────────────────────────────────────────────────────────────────────
 * Room info — fetched once on mount.
 * ──────────────────────────────────────────────────────────────────── */
interface GuestRoomInfo {
    room_name: string;
    room_status: string | null;
    display_name: string | null;
    expires_at: string | null;
}

const roomInfo = ref<GuestRoomInfo | null>(null);
const lookupError = ref<string | null>(null);
const lookupLoading = ref(true);

async function loadRoom(): Promise<void> {
    lookupLoading.value = true;
    lookupError.value = null;
    try {
        const { data } = await axios.get<GuestRoomInfo>(`/api/prime-connect/guest/${props.token}`);
        roomInfo.value = data;
    } catch (e: unknown) {
        const status = (e as { response?: { status?: number } }).response?.status;
        lookupError.value = status === 404
            ? 'This invite link has expired or is no longer valid.'
            : 'We couldn\'t load the call. Please try again in a moment.';
    } finally {
        lookupLoading.value = false;
    }
}

onMounted(loadRoom);

/* ──────────────────────────────────────────────────────────────────────
 * Bridge — same Twilio composable as the staff side, with a custom
 * token fetcher that hits the PUBLIC guest endpoint.
 * ──────────────────────────────────────────────────────────────────── */
const bridge = useTwilioBridge();
const joining = ref(false);
const joined = computed(() => bridge.state.value === 'connected' || bridge.state.value === 'reconnecting');

async function fetchGuestToken(): Promise<{ token: string; identity: string }> {
    const { data } = await axios.post<{ token: string; identity: string }>(
        `/api/prime-connect/guest/${props.token}/access-token`,
        {},
    );
    return { token: data.token, identity: data.identity };
}

async function joinCall(): Promise<void> {
    if (!roomInfo.value || joining.value) return;
    joining.value = true;
    try {
        await bridge.connect({
            roomName: roomInfo.value.room_name,
            role: 'customer',
            fetchAccessToken: fetchGuestToken,
        });
    } catch {
        // bridge surfaces the error via lastError; we render it below.
    } finally {
        joining.value = false;
    }
}

function leaveCall(): void {
    bridge.disconnect();
}

onBeforeUnmount(() => {
    bridge.disconnect();
});

/* ──────────────────────────────────────────────────────────────────────
 * DOM track attachment — same pattern as the staff ActiveCall.vue but
 * stripped-down (one local <video>, one main remote <video>, hidden
 * mount for remote audio so customers hear staff regardless of which
 * tile is dominant).
 * ──────────────────────────────────────────────────────────────────── */
const selfVideoEl = ref<HTMLVideoElement | null>(null);
const remoteVideoEl = ref<HTMLVideoElement | null>(null);
const remoteAudioMount = ref<HTMLDivElement | null>(null);

watch(
    () => bridge.localVideoTrack.value,
    (track, prev) => {
        if (prev) { try { prev.detach().forEach((el) => el.remove()); } catch { /* */ } }
        if (track && selfVideoEl.value) {
            try { track.attach(selfVideoEl.value); } catch { /* */ }
        }
    },
    { immediate: true },
);

const primaryRemote = computed(() => {
    const map = bridge.remoteParticipants.value;
    const dominant = bridge.dominantSpeakerIdentity.value;
    if (dominant && map.has(dominant)) return map.get(dominant)!;
    const first = map.values().next();
    return first.done ? null : first.value;
});

watch(
    () => primaryRemote.value?.videoTrack ?? null,
    (track, prev) => {
        if (prev) { try { prev.detach().forEach((el) => el.remove()); } catch { /* */ } }
        if (track && remoteVideoEl.value) {
            try { track.attach(remoteVideoEl.value); } catch { /* */ }
        }
    },
);

watch(
    () => bridge.remoteParticipants.value,
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

/* ──────────────────────────────────────────────────────────────────────
 * Hardware toggles. Just mute + camera off — no screen share for the
 * customer (they're not the one presenting).
 * ──────────────────────────────────────────────────────────────────── */
const muted = computed(() => bridge.isAudioMuted.value);
const cameraOn = computed(() => !bridge.isVideoOff.value);
const recording = computed(() => bridge.isRecording.value);
function toggleMute(): void { bridge.toggleAudio(); }
function toggleCamera(): void { bridge.toggleVideo(); }

const greeting = computed(() => roomInfo.value?.display_name ?? 'You\'re invited to a video call');
</script>

<template>
    <!--
      Public guest page. No AppLayout, no sidebar, no auth requirements.
      Full-bleed dark canvas matches the staff in-call view so the brand
      transition between agent-shared link and customer landing is silent.
    -->
    <div class="flex min-h-screen w-full flex-col bg-black text-white">
        <!-- Slim header — brand strip only -->
        <header class="flex items-center justify-between border-b border-white/5 px-6 py-3 text-sm">
            <span class="font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-floor-accent">
                Prime Connect
            </span>
            <span v-if="joined" class="font-mono text-[11px] uppercase tracking-wider text-white/50">
                connected
            </span>
        </header>

        <!-- ════════════════════════════════════════════════════════════
             Lookup states — loading / expired / not-yet-joined / in-call
             ════════════════════════════════════════════════════════════ -->
        <main class="relative flex-1">
            <!-- Loading -->
            <div
                v-if="lookupLoading"
                class="absolute inset-0 flex items-center justify-center"
            >
                <div class="text-sm text-white/60">Loading your call…</div>
            </div>

            <!-- Invalid / expired token -->
            <div
                v-else-if="lookupError"
                class="absolute inset-0 flex items-center justify-center px-6"
            >
                <div class="max-w-md text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-rose-500/15 ring-1 ring-rose-500/30">
                        <span class="text-2xl">⌛</span>
                    </div>
                    <h2 class="mt-4 text-xl font-semibold">Link no longer active</h2>
                    <p class="mt-2 text-sm text-white/60">{{ lookupError }}</p>
                    <p class="mt-4 text-xs text-white/40">
                        Please contact the person who shared this link and ask for a new one.
                    </p>
                </div>
            </div>

            <!-- Pre-join landing — confirm + Join button -->
            <div
                v-else-if="roomInfo && !joined"
                class="absolute inset-0 flex items-center justify-center px-6"
            >
                <div class="w-full max-w-md text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-floor-accent/20 ring-2 ring-floor-accent/40">
                        <span class="text-2xl text-floor-accent">📹</span>
                    </div>
                    <h1 class="mt-5 text-2xl font-semibold">{{ greeting }}</h1>
                    <p class="mt-2 text-sm text-white/60">
                        Click below to join. We'll ask for camera & microphone permission.
                    </p>

                    <button
                        type="button"
                        class="mt-8 w-full rounded-md bg-floor-accent px-6 py-3 text-sm font-semibold uppercase tracking-wider text-deck-bg transition-colors hover:bg-floor-accentHi disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="joining"
                        @click="joinCall"
                    >
                        {{ joining ? 'Connecting…' : 'Join the call' }}
                    </button>

                    <p
                        v-if="bridge.lastError.value"
                        class="mt-4 text-xs text-rose-300"
                    >
                        {{ bridge.lastError.value }}
                    </p>

                    <p class="mt-6 text-[11px] uppercase tracking-wider text-white/30">
                        This call is recorded for quality and compliance.
                    </p>
                </div>
            </div>

            <!-- In-call -->
            <div v-else-if="joined" class="absolute inset-0 flex flex-col">
                <!-- Remote (agent) main feed -->
                <div class="relative flex-1 bg-black">
                    <video
                        ref="remoteVideoEl"
                        class="h-full w-full object-cover"
                        :class="{ 'opacity-0': !primaryRemote?.videoTrack }"
                        autoplay
                        playsinline
                    ></video>
                    <div
                        v-if="!primaryRemote?.videoTrack"
                        class="absolute inset-0 flex items-center justify-center bg-[radial-gradient(ellipse_at_center,_#1a2238,_#05080f)]"
                    >
                        <div class="text-center">
                            <div class="mx-auto h-20 w-20 rounded-full bg-white/5 ring-1 ring-white/10"></div>
                            <div class="mt-3 text-lg font-medium">Waiting for the other side…</div>
                        </div>
                    </div>

                    <div ref="remoteAudioMount" class="hidden" aria-hidden="true"></div>

                    <!-- Recording badge — same two-state UX as the staff side -->
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
                    </div>

                    <!-- Self-view — picture-in-picture, top-right -->
                    <div class="absolute right-4 top-4 h-32 w-44 overflow-hidden rounded-md bg-black ring-1 ring-white/10">
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
                        <div
                            v-if="!cameraOn"
                            class="absolute inset-0 flex items-center justify-center bg-black/85 text-[11px] text-white/60"
                        >Camera off</div>
                    </div>
                </div>

                <!-- Control pill — mute, camera, leave -->
                <div class="flex justify-center border-t border-white/5 bg-black/70 p-3">
                    <div class="flex items-center gap-2 rounded-full bg-white/[0.04] p-1.5 ring-1 ring-white/10">
                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                            :class="muted
                                ? 'bg-rose-500/85 text-white hover:bg-rose-500'
                                : 'bg-white/5 text-white/80 hover:bg-white/15'"
                            :title="muted ? 'Unmute' : 'Mute'"
                            @click="toggleMute"
                        >
                            {{ muted ? '🎤❌' : '🎤' }}
                        </button>
                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-full transition-colors"
                            :class="!cameraOn
                                ? 'bg-rose-500/85 text-white hover:bg-rose-500'
                                : 'bg-white/5 text-white/80 hover:bg-white/15'"
                            :title="cameraOn ? 'Turn camera off' : 'Turn camera on'"
                            @click="toggleCamera"
                        >
                            {{ cameraOn ? '📷' : '📷❌' }}
                        </button>
                        <span class="mx-1 h-6 w-px bg-white/10"></span>
                        <button
                            type="button"
                            class="flex h-10 items-center gap-1.5 rounded-full bg-rose-600 px-4 text-[11px] font-semibold uppercase tracking-wider text-white hover:bg-rose-500"
                            title="Leave the call"
                            @click="leaveCall"
                        >
                            Leave call
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
