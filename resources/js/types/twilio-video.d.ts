/**
 * Minimal type shim for `twilio-video`.
 *
 * The npm package ships its own type definitions, but they're only
 * present after a successful `npm install` — local dev environments
 * that predate the twilio-video addition (and CI that runs vue-tsc
 * before the install step) hit "Cannot find module" errors otherwise.
 *
 * The shape here covers the subset of the SDK the bridge actually
 * uses. When the real types are present, this file is harmlessly
 * superseded (TypeScript module-augments rather than overwrites).
 */
declare module 'twilio-video' {
    export interface MediaTrack {
        readonly kind: 'audio' | 'video';
        readonly name: string;
        readonly isEnabled: boolean;
        enable(): void;
        disable(): void;
        stop(): void;
        attach(element?: HTMLMediaElement): HTMLMediaElement;
        detach(element?: HTMLMediaElement): HTMLMediaElement[];
    }

    export interface LocalAudioTrack extends MediaTrack {
        readonly kind: 'audio';
    }

    export interface LocalVideoTrack extends MediaTrack {
        readonly kind: 'video';
    }

    export interface RemoteAudioTrack extends MediaTrack {
        readonly kind: 'audio';
    }

    export interface RemoteVideoTrack extends MediaTrack {
        readonly kind: 'video';
    }

    export type LocalTrack = LocalAudioTrack | LocalVideoTrack;
    export type RemoteTrack = RemoteAudioTrack | RemoteVideoTrack;

    export interface TrackPublication {
        readonly track: MediaTrack | null;
        readonly kind: 'audio' | 'video';
    }

    export interface LocalParticipant {
        readonly identity: string;
        readonly sid: string;
        publishTrack(track: LocalTrack): Promise<TrackPublication>;
        unpublishTrack(track: LocalTrack): TrackPublication | null;
        // Loose listener signature — Twilio's actual events have
        // per-event payload shapes that our consumers narrow inline.
        // The real types overload `on` per event; we don't bother
        // since the shim is only loaded when the SDK's types are
        // absent (a local-dev convenience, not a production contract).
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        on(event: string, listener: (...args: any[]) => void): this;
    }

    export interface RemoteParticipant {
        readonly identity: string;
        readonly sid: string;
        readonly tracks: Map<string, TrackPublication>;
        // Loose listener signature — Twilio's actual events have
        // per-event payload shapes that our consumers narrow inline.
        // The real types overload `on` per event; we don't bother
        // since the shim is only loaded when the SDK's types are
        // absent (a local-dev convenience, not a production contract).
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        on(event: string, listener: (...args: any[]) => void): this;
    }

    export interface Room {
        readonly name: string;
        readonly sid: string;
        readonly isRecording: boolean;
        readonly localParticipant: LocalParticipant;
        readonly participants: Map<string, RemoteParticipant>;
        disconnect(): void;
        // Loose listener signature — Twilio's actual events have
        // per-event payload shapes that our consumers narrow inline.
        // The real types overload `on` per event; we don't bother
        // since the shim is only loaded when the SDK's types are
        // absent (a local-dev convenience, not a production contract).
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        on(event: string, listener: (...args: any[]) => void): this;
    }

    export interface CreateLocalTrackOptions {
        deviceId?: string | { exact: string };
        height?: number;
        width?: number;
        frameRate?: number;
    }

    export function createLocalTracks(options?: {
        audio?: boolean | CreateLocalTrackOptions;
        video?: boolean | CreateLocalTrackOptions;
    }): Promise<LocalTrack[]>;

    export function connect(
        token: string,
        options?: {
            name?: string;
            tracks?: LocalTrack[];
            dominantSpeaker?: boolean;
            networkQuality?: { local?: number; remote?: number };
        },
    ): Promise<Room>;

    // The SDK exposes these as constructible classes; the actual
    // constructor parameters are richer but the bridge only uses the
    // two-argument form.
    export class LocalVideoTrack {
        constructor(mediaStreamTrack: MediaStreamTrack, options?: { name?: string });
    }
    export class LocalAudioTrack {
        constructor(mediaStreamTrack: MediaStreamTrack, options?: { name?: string });
    }
}
