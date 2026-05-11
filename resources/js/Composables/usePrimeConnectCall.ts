/**
 * usePrimeConnectCall — page-state wrapper around useTwilioBridge.
 *
 * Distinct from `useActiveCall` (the voice-call flow that tracks the
 * Call entity through queued / initiated / ringing / in_progress). This
 * one is the *video room* side:
 *
 *   1. POST /api/prime-connect/rooms        → provision a Twilio room
 *   2. POST /api/prime-connect/access-token → mint a JWT for that room
 *   3. Video.connect()                       → join via the bridge
 *
 * The room is a database row separate from the Call entity. Future
 * work ties the two: when an inbound call comes in, the war room
 * spawns a Prime Connect room with the same external_call_id, so
 * voice and video for the same conversation share a recording.
 *
 * Owns:
 *   • active: { intent, roomName, roomId, answeredAt } | null
 *   • Bridge (re-exported so children can subscribe to track refs)
 *   • start(intent) / end()
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { useTwilioBridge, type RoomRole } from '@/Composables/useTwilioBridge';

export interface CallIntent {
    leadId: string | null;
    leadName: string | null;
    scheduledCallId: string | null;
}

export interface ActiveCallInfo {
    intent: CallIntent;
    /** Twilio room name (matches the partner_sites listing's resource_sid pattern). */
    roomName: string;
    /** Our DB row id for the room — used for end/recording/audit. */
    roomId: string;
    /** Wall-clock ISO of when the local participant entered the room. */
    answeredAt: string;
}

interface CreateRoomResponse {
    data: {
        id: string;
        room_name: string;
        twilio_room_sid: string;
        room_status: string;
    };
}

export function usePrimeConnectCall() {
    const bridge = useTwilioBridge();
    const active = ref<ActiveCallInfo | null>(null);
    const isStarting = ref(false);
    const lastError = ref<string | null>(null);

    const isLive = computed(() =>
        active.value !== null && bridge.state.value === 'connected'
    );

    async function start(intent: CallIntent, opts?: {
        role?: RoomRole;
        videoDeviceId?: string;
        audioDeviceId?: string;
        startMuted?: boolean;
        startCameraOff?: boolean;
        /**
         * Join an EXISTING room rather than provisioning a new one.
         * When set, we skip the POST /rooms call and connect straight
         * to this room_name with a fresh access token. Used by invite
         * links and the "active sessions" list — anywhere user B is
         * landing in user A's room.
         *
         * roomId is the DB row id (matches roomName 1-1 server-side).
         * When omitted, end() can't issue a DELETE; that's fine, the
         * room's auto-end webhook reconciles when the last participant
         * leaves.
         */
        joinRoomName?: string;
        joinRoomId?: string;
    }): Promise<void> {
        if (isStarting.value || active.value !== null) return;
        isStarting.value = true;
        lastError.value = null;

        try {
            let roomName: string;
            let roomId: string;

            if (opts?.joinRoomName) {
                // Join path — caller already knows the room name (from
                // an invite link or the active-sessions list). Skip the
                // POST /rooms call entirely; the access-token endpoint
                // will mint a JWT scoped to this room and Twilio's
                // server-side state handles routing both participants
                // into the same room.
                roomName = opts.joinRoomName;
                roomId = opts.joinRoomId ?? '';
            } else {
                // Provision path — first participant in the room.
                const { data } = await axios.post<CreateRoomResponse>(
                    '/api/prime-connect/rooms',
                    {
                        lead_id: intent.leadId,
                        scheduled_call_id: intent.scheduledCallId,
                    },
                );
                roomName = data.data.room_name;
                roomId = data.data.id;
            }

            // Connect the bridge — same path for both provision + join.
            await bridge.connect({
                roomName,
                role: opts?.role ?? 'agent',
                videoDeviceId: opts?.videoDeviceId,
                audioDeviceId: opts?.audioDeviceId,
                startMuted: opts?.startMuted,
                startCameraOff: opts?.startCameraOff,
            });

            active.value = {
                intent,
                roomName,
                roomId,
                answeredAt: new Date().toISOString(),
            };
        } catch (e: unknown) {
            lastError.value = errorMessage(e);
            // Tear down any partial connection — don't leave the camera
            // light on after a failed connect.
            bridge.disconnect();
            throw e;
        } finally {
            isStarting.value = false;
        }
    }

    async function end(): Promise<void> {
        const a = active.value;
        // 1. Locally disconnect first — release camera/mic immediately
        //    so the indicator goes dark even if the server request hangs.
        bridge.disconnect();

        // 2. Tell the server the room is over IF we own it (we
        //    provisioned it and have a roomId). When we joined someone
        //    else's room, hanging up shouldn't kill the room for the
        //    other participant — we just disconnect locally. Twilio's
        //    room-ended webhook reconciles when the last participant
        //    leaves the actual room.
        if (a !== null && a.roomId !== '') {
            try {
                await axios.delete(`/api/prime-connect/rooms/${a.roomId}`);
            } catch {
                // ignore — server reconciliation catches orphan rooms
                // via Twilio's status webhook
            }
        }

        active.value = null;
    }

    function errorMessage(e: unknown): string {
        if (e instanceof Error) return e.message;
        if (typeof e === 'object' && e !== null && 'message' in e) {
            return String((e as { message: unknown }).message);
        }
        return 'Could not start the call.';
    }

    return {
        bridge,
        active,
        isLive,
        isStarting,
        lastError,
        start,
        end,
    };
}
