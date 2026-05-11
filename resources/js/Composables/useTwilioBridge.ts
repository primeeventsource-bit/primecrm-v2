/**
 * Twilio Video SDK bridge.
 *
 * Wraps the twilio-video JS package in a Vue-friendly composable so
 * Pages/PrimeConnect and Components/PrimeConnect/* never touch the
 * SDK directly. The bridge owns:
 *
 *   • The local LocalTracks (audio + video). Released on disconnect.
 *   • The Room instance and its event subscriptions.
 *   • A reactive set of RemoteParticipants and their attached
 *     audio/video tracks, keyed by participant identity.
 *   • A dominantSpeakerIdentity ref so the asymmetric grid in
 *     ActiveCall.vue can show whoever is talking in the big tile
 *     without the parent re-computing on every track event.
 *
 * Identity convention: "{role}:{userId}" matches the backend
 * PrimeConnectAccessTokenController. The bridge parses both parts off
 * the identity string when needed (see roleOf / userIdOf helpers).
 *
 * Lifecycle:
 *
 *   ┌─ connect(roomName, opts) ────────────────────────────────────┐
 *   │  1. fetchToken() → POST /api/prime-connect/access-token       │
 *   │  2. createLocalTracks() with selected device ids (if any)     │
 *   │  3. Video.connect(jwt, { name, tracks, dominantSpeaker:true }) │
 *   │  4. Subscribe to participantConnected / participantDisconnected│
 *   │     / disconnected / reconnecting / reconnected events        │
 *   │  5. For each existing participant: bind track events           │
 *   │  Returns the Room handle; state ref flips to 'connected'.    │
 *   └──────────────────────────────────────────────────────────────┘
 *
 *   disconnect()
 *     room.disconnect() + LocalTracks.stop() for each. Browsers
 *     only release the camera/mic indicator when tracks are .stop()ed.
 *
 * Errors surface via state='failed' + lastError ref. Caller decides
 * UX (toast vs banner vs reconnect button).
 */
import { ref, shallowRef, type Ref } from 'vue';
import axios from 'axios';
import type {
    LocalAudioTrack,
    LocalVideoTrack,
    RemoteAudioTrack,
    RemoteParticipant,
    RemoteVideoTrack,
    Room,
} from 'twilio-video';

export type BridgeState =
    | 'idle'
    | 'fetching-token'
    | 'acquiring-media'
    | 'connecting'
    | 'connected'
    | 'reconnecting'
    | 'disconnected'
    | 'failed';

export type RoomRole =
    | 'agent'
    | 'customer'
    | 'supervisor_listen'
    | 'supervisor_whisper'
    | 'supervisor_barge';

export interface ConnectOptions {
    roomName: string;
    role: RoomRole;
    /**
     * deviceId from navigator.mediaDevices.enumerateDevices(). Both
     * optional — when omitted, the SDK picks the user's default.
     */
    videoDeviceId?: string;
    audioDeviceId?: string;
    /**
     * Initial mute state on join. Defaults to false; useful when the
     * lobby's device check showed the user muted before joining.
     */
    startMuted?: boolean;
    startCameraOff?: boolean;
}

export interface RemoteParticipantState {
    /** Full identity: "role:userId". */
    identity: string;
    role: string;
    userId: string;
    /** sid is the runtime-stable id; identity is the human one. */
    sid: string;
    audioTrack: RemoteAudioTrack | null;
    videoTrack: RemoteVideoTrack | null;
    /**
     * Last network-quality score we received from the SDK (0-5).
     * Drives the connection-quality pill on the in-call view.
     */
    networkQualityLevel: number | null;
}

interface AccessTokenResponse {
    token: string;
    identity: string;
    expires_at: string;
}

/**
 * Pull the role/userId out of a Twilio identity string. Defensive —
 * if the format ever drifts, we still produce something usable.
 */
function parseIdentity(identity: string): { role: string; userId: string } {
    const idx = identity.indexOf(':');
    if (idx === -1) return { role: 'agent', userId: identity };
    return { role: identity.slice(0, idx), userId: identity.slice(idx + 1) };
}

export function useTwilioBridge() {
    /* --------------------------------------------------------------
     * State
     * -------------------------------------------------------------- */

    const state = ref<BridgeState>('idle');
    const lastError = ref<string | null>(null);

    // shallowRef for SDK objects — we don't want Vue's deep reactivity
    // wrapping LocalTrack / Room (they have circular refs and noisy
    // properties). Reactivity here is "the reference changed", not
    // "a deep property changed".
    const room = shallowRef<Room | null>(null);
    const localAudioTrack = shallowRef<LocalAudioTrack | null>(null);
    const localVideoTrack = shallowRef<LocalVideoTrack | null>(null);

    // Remote participants — Map keyed by identity, wrapped in ref so
    // Vue picks up additions/deletions. The inner objects use
    // shallowRef internally for the track refs.
    const remoteParticipants: Ref<Map<string, RemoteParticipantState>> = ref(new Map());

    const dominantSpeakerIdentity = ref<string | null>(null);
    const networkQualityLevel = ref<number | null>(null);
    const isAudioMuted = ref(false);
    const isVideoOff = ref(false);

    /* --------------------------------------------------------------
     * Public API
     * -------------------------------------------------------------- */

    async function connect(opts: ConnectOptions): Promise<Room> {
        lastError.value = null;

        try {
            // 1. Mint a Twilio JWT scoped to this room+role.
            state.value = 'fetching-token';
            const { data } = await axios.post<AccessTokenResponse>(
                '/api/prime-connect/access-token',
                {
                    role: opts.role,
                    room_name: opts.roomName,
                },
            );

            // 2. Acquire local media. Dynamic-import the SDK so the
            //    main app bundle doesn't pay the cost of twilio-video's
            //    ~300KB until a user actually opens Prime Connect.
            state.value = 'acquiring-media';
            const Video = await import('twilio-video');

            const tracks = await Video.createLocalTracks({
                audio: opts.audioDeviceId
                    ? { deviceId: { exact: opts.audioDeviceId } }
                    : true,
                video: opts.videoDeviceId
                    ? { deviceId: { exact: opts.videoDeviceId } }
                    : { height: 720, frameRate: 24, width: 1280 },
            });

            const localAudio = tracks.find((t): t is LocalAudioTrack => t.kind === 'audio') ?? null;
            const localVideo = tracks.find((t): t is LocalVideoTrack => t.kind === 'video') ?? null;

            if (opts.startMuted && localAudio) {
                localAudio.disable();
                isAudioMuted.value = true;
            }
            if (opts.startCameraOff && localVideo) {
                localVideo.disable();
                isVideoOff.value = true;
            }

            localAudioTrack.value = localAudio;
            localVideoTrack.value = localVideo;

            // 3. Connect to the room.
            state.value = 'connecting';
            const r = await Video.connect(data.token, {
                name: opts.roomName,
                tracks,
                dominantSpeaker: true,
                networkQuality: { local: 1, remote: 1 },
            });

            wireRoom(r);
            room.value = r;
            state.value = 'connected';
            return r;
        } catch (e: unknown) {
            lastError.value = errorMessage(e);
            state.value = 'failed';
            await cleanupLocalTracks();
            throw e;
        }
    }

    function disconnect(): void {
        const r = room.value;
        if (r) {
            try {
                r.disconnect();
            } catch {
                // already-disconnected rooms throw; ignore
            }
        }
        cleanupLocalTracks();
        remoteParticipants.value = new Map();
        dominantSpeakerIdentity.value = null;
        networkQualityLevel.value = null;
        room.value = null;
        state.value = 'disconnected';
    }

    function toggleAudio(): boolean {
        const t = localAudioTrack.value;
        if (!t) return isAudioMuted.value;
        if (t.isEnabled) {
            t.disable();
            isAudioMuted.value = true;
        } else {
            t.enable();
            isAudioMuted.value = false;
        }
        return isAudioMuted.value;
    }

    function toggleVideo(): boolean {
        const t = localVideoTrack.value;
        if (!t) return isVideoOff.value;
        if (t.isEnabled) {
            t.disable();
            isVideoOff.value = true;
        } else {
            t.enable();
            isVideoOff.value = false;
        }
        return isVideoOff.value;
    }

    /* --------------------------------------------------------------
     * Internals
     * -------------------------------------------------------------- */

    function wireRoom(r: Room): void {
        // Existing participants (rare: when joining a room mid-call).
        r.participants.forEach((p) => upsertRemoteParticipant(p));

        r.on('participantConnected', (p: RemoteParticipant) => upsertRemoteParticipant(p));
        r.on('participantDisconnected', (p: RemoteParticipant) => {
            const next = new Map(remoteParticipants.value);
            next.delete(p.identity);
            remoteParticipants.value = next;
        });
        r.on('dominantSpeakerChanged', (participant: RemoteParticipant | null) => {
            dominantSpeakerIdentity.value = participant?.identity ?? null;
        });
        r.on('reconnecting', () => {
            state.value = 'reconnecting';
        });
        r.on('reconnected', () => {
            state.value = 'connected';
        });
        r.on('disconnected', (_room: Room, err: Error | null) => {
            if (err) lastError.value = err.message;
            state.value = 'disconnected';
            cleanupLocalTracks();
            remoteParticipants.value = new Map();
            room.value = null;
        });

        // Local network quality — drives the connection-quality pill.
        r.localParticipant.on('networkQualityLevelChanged', (level: number) => {
            networkQualityLevel.value = level;
        });
    }

    function upsertRemoteParticipant(p: RemoteParticipant): void {
        const { role, userId } = parseIdentity(p.identity);
        const existing = remoteParticipants.value.get(p.identity);
        const entry: RemoteParticipantState = existing ?? {
            identity: p.identity,
            role,
            userId,
            sid: p.sid,
            audioTrack: null,
            videoTrack: null,
            networkQualityLevel: null,
        };

        // Attach already-subscribed tracks (existing participants).
        p.tracks.forEach((pub) => {
            const t = pub.track;
            if (!t) return;
            if (t.kind === 'audio') entry.audioTrack = t as RemoteAudioTrack;
            else if (t.kind === 'video') entry.videoTrack = t as RemoteVideoTrack;
        });

        // Live track lifecycle.
        p.on('trackSubscribed', (t) => {
            const cur = remoteParticipants.value.get(p.identity);
            if (!cur) return;
            const next = { ...cur };
            if (t.kind === 'audio') next.audioTrack = t as RemoteAudioTrack;
            else if (t.kind === 'video') next.videoTrack = t as RemoteVideoTrack;
            const m = new Map(remoteParticipants.value);
            m.set(p.identity, next);
            remoteParticipants.value = m;
        });
        p.on('trackUnsubscribed', (t) => {
            const cur = remoteParticipants.value.get(p.identity);
            if (!cur) return;
            const next = { ...cur };
            if (t.kind === 'audio' && next.audioTrack === t) next.audioTrack = null;
            else if (t.kind === 'video' && next.videoTrack === t) next.videoTrack = null;
            const m = new Map(remoteParticipants.value);
            m.set(p.identity, next);
            remoteParticipants.value = m;
        });
        p.on('networkQualityLevelChanged', (level: number) => {
            const cur = remoteParticipants.value.get(p.identity);
            if (!cur) return;
            const m = new Map(remoteParticipants.value);
            m.set(p.identity, { ...cur, networkQualityLevel: level });
            remoteParticipants.value = m;
        });

        const m = new Map(remoteParticipants.value);
        m.set(p.identity, entry);
        remoteParticipants.value = m;
    }

    function cleanupLocalTracks(): void {
        const a = localAudioTrack.value;
        const v = localVideoTrack.value;
        if (a) {
            try { a.stop(); } catch { /* ignore */ }
        }
        if (v) {
            try { v.stop(); } catch { /* ignore */ }
        }
        localAudioTrack.value = null;
        localVideoTrack.value = null;
        isAudioMuted.value = false;
        isVideoOff.value = false;
    }

    function errorMessage(e: unknown): string {
        if (e instanceof Error) return e.message;
        if (typeof e === 'object' && e !== null && 'message' in e) {
            return String((e as { message: unknown }).message);
        }
        return 'Unknown error connecting to Twilio.';
    }

    return {
        // State refs (readonly to consumers in practice — they shouldn't mutate)
        state,
        lastError,
        room,
        localAudioTrack,
        localVideoTrack,
        remoteParticipants,
        dominantSpeakerIdentity,
        networkQualityLevel,
        isAudioMuted,
        isVideoOff,

        // Actions
        connect,
        disconnect,
        toggleAudio,
        toggleVideo,
    };
}
