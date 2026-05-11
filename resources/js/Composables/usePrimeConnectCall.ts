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
    }): Promise<void> {
        if (isStarting.value || active.value !== null) return;
        isStarting.value = true;
        lastError.value = null;

        try {
            // 1. Provision the room server-side. The controller calls
            //    Twilio's REST API to create the room, persists a row,
            //    and returns the canonical room_name. The browser uses
            //    that name as the join target.
            const { data } = await axios.post<CreateRoomResponse>(
                '/api/prime-connect/rooms',
                {
                    lead_id: intent.leadId,
                    scheduled_call_id: intent.scheduledCallId,
                },
            );

            // 2. Connect the bridge to that room.
            await bridge.connect({
                roomName: data.data.room_name,
                role: opts?.role ?? 'agent',
                videoDeviceId: opts?.videoDeviceId,
                audioDeviceId: opts?.audioDeviceId,
                startMuted: opts?.startMuted,
                startCameraOff: opts?.startCameraOff,
            });

            active.value = {
                intent,
                roomName: data.data.room_name,
                roomId: data.data.id,
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

        // 2. Tell the server the room is over. Server-side this ends
        //    the Twilio room and writes the closed_at + duration.
        //    Best-effort: a network error here doesn't undo the local
        //    teardown (the user expects the call to end NOW).
        if (a !== null) {
            try {
                await axios.delete(`/api/prime-connect/rooms/${a.roomId}`);
            } catch {
                // ignore — server reconciliation will catch orphan rooms
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
