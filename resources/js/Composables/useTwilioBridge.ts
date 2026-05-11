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

    // Screen share state. The screen-share approach is track-swap:
    // when sharing starts we unpublish the camera track and publish
    // a screen track in its place; when sharing stops we reverse.
    // This keeps the bridge's "one video track per participant" model
    // (which the receiver renders without modification) while still
    // letting the screen replace the face on the main canvas remotely.
    const isScreenSharing = ref(false);
    const localScreenTrack = shallowRef<LocalVideoTrack | null>(null);
    // Stash the camera track while we're screen-sharing so we can
    // re-publish it cleanly when the user stops.
    const cameraTrackStash = shallowRef<LocalVideoTrack | null>(null);

    // Recording state — driven by Room.recordingStarted / recordingStopped
    // events. Twilio fires these when the room's recording status
    // changes server-side (e.g., a supervisor toggles recording).
    const isRecording = ref(false);

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

    /**
     * Toggle screen share. On start we replace the camera track with a
     * fresh screen-capture track; on stop we replace it back. Throws
     * (via the surrounding state machine) if the user dismisses the
     * browser's screen-picker dialog — caller catches via lastError.
     *
     * Browser-initiated stop (the "Stop sharing" overlay at the top of
     * the page) fires `ended` on the underlying MediaStreamTrack; we
     * subscribe and reverse the swap automatically so the agent
     * doesn't end up off-camera silently.
     */
    async function toggleScreenShare(): Promise<boolean> {
        const r = room.value;
        if (!r) return isScreenSharing.value;

        const Video = await import('twilio-video');

        // ───── Start sharing ─────
        if (! isScreenSharing.value) {
            let displayStream: MediaStream;
            try {
                displayStream = await navigator.mediaDevices.getDisplayMedia({
                    video: { frameRate: 15 },
                    audio: false, // system audio rarely useful; saves bandwidth + permission friction
                });
            } catch {
                // User cancelled the picker or denied permission — no-op.
                return false;
            }

            const screenMediaTrack = displayStream.getVideoTracks()[0];
            if (! screenMediaTrack) return false;

            // Wrap the MediaStreamTrack in a Twilio LocalVideoTrack so
            // we can use the participant.publishTrack flow uniformly.
            const screenTrack = new Video.LocalVideoTrack(screenMediaTrack, {
                name: 'screen',
            });

            // Stash + unpublish the camera track. The bridge's
            // localVideoTrack ref points at whatever's currently
            // published so the self-view reflects what remotes see.
            const camera = localVideoTrack.value;
            if (camera) {
                try { r.localParticipant.unpublishTrack(camera); } catch { /* */ }
                cameraTrackStash.value = camera;
            }

            // Publish the screen track. Remote participants get a
            // trackSubscribed event and the bridge's existing handler
            // attaches it to their main canvas.
            try {
                await r.localParticipant.publishTrack(screenTrack);
            } catch (e) {
                lastError.value = errorMessage(e);
                // Couldn't publish — release the captured stream so the
                // browser's screen-share indicator goes away.
                try { screenTrack.stop(); } catch { /* */ }
                screenMediaTrack.stop();
                // Re-publish camera since we already unpublished it.
                if (camera) {
                    try { await r.localParticipant.publishTrack(camera); } catch { /* */ }
                    cameraTrackStash.value = null;
                }
                return false;
            }

            // Browser-initiated stop ("Stop sharing" overlay).
            screenMediaTrack.addEventListener('ended', () => {
                // Re-entrant call into ourselves to reverse the swap.
                // Guard so we don't double-stop if the user clicked
                // our button at the same time as the browser's.
                if (isScreenSharing.value) void toggleScreenShare();
            });

            localScreenTrack.value = screenTrack;
            localVideoTrack.value = screenTrack; // self-view + remote both show screen
            isScreenSharing.value = true;
            return true;
        }

        // ───── Stop sharing ─────
        const screen = localScreenTrack.value;
        if (screen) {
            try { r.localParticipant.unpublishTrack(screen); } catch { /* */ }
            try { screen.stop(); } catch { /* */ }
            // detach DOM elements the consumer attached via attach()
            try { screen.detach().forEach((el) => el.remove()); } catch { /* */ }
        }
        localScreenTrack.value = null;

        const camera = cameraTrackStash.value;
        if (camera) {
            try {
                await r.localParticipant.publishTrack(camera);
                localVideoTrack.value = camera;
            } catch (e) {
                lastError.value = errorMessage(e);
                localVideoTrack.value = null;
            }
        } else {
            localVideoTrack.value = null;
        }
        cameraTrackStash.value = null;
        isScreenSharing.value = false;
        return false;
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
            isRecording.value = false;
            room.value = null;
        });

        // Recording lifecycle — Twilio fires these when the room's
        // recording status changes server-side. The default behavior
        // for rooms created by our backend is "always recording" once
        // a participant joins, but a supervisor can pause/resume via
        // the REST API; the UI must reflect that immediately so the
        // TCPA disclosure indicator is never a lie.
        if (r.isRecording) isRecording.value = true;
        r.on('recordingStarted', () => { isRecording.value = true; });
        r.on('recordingStopped', () => { isRecording.value = false; });

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
        const s = localScreenTrack.value;
        const cam = cameraTrackStash.value;
        if (a) { try { a.stop(); } catch { /* */ } }
        if (v) { try { v.stop(); } catch { /* */ } }
        if (s) { try { s.stop(); } catch { /* */ } }
        // The stashed camera may NOT be the same as v (if we're mid-
        // screen-share, v is the screen track; the camera is parked
        // here). Stop it too so the indicator goes dark.
        if (cam && cam !== v) { try { cam.stop(); } catch { /* */ } }
        localAudioTrack.value = null;
        localVideoTrack.value = null;
        localScreenTrack.value = null;
        cameraTrackStash.value = null;
        isAudioMuted.value = false;
        isVideoOff.value = false;
        isScreenSharing.value = false;
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
        localScreenTrack,
        remoteParticipants,
        dominantSpeakerIdentity,
        networkQualityLevel,
        isAudioMuted,
        isVideoOff,
        isScreenSharing,
        isRecording,

        // Actions
        connect,
        disconnect,
        toggleAudio,
        toggleVideo,
        toggleScreenShare,
    };
}
