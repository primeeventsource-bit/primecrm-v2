import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// laravel-echo v1's typings made `Echo` generic over a broadcaster
// driver name. We always speak 'pusher', so pin the generic at module
// scope to keep call sites readable.
export type PusherEcho = Echo<'pusher'>;

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: PusherEcho;
    }
}

/**
 * Lazily wire up Echo. Called from app.ts with the per-deploy config that
 * Laravel injects into Inertia's initialPage props (see HandleInertiaRequests).
 *
 * Keeping this in a function (rather than at module scope) means tests
 * that don't need WebSockets can stub or skip the call.
 *
 * Empty `key` short-circuits the whole setup. With an empty key the
 * Pusher client used to keep retrying the WebSocket forever (~1s,
 * exponential backoff to 32s) — visible as a hundred-plus
 * `WebSocket connection ... failed` console errors and a network panel
 * full of failing handshakes. On Cloud envs that haven't been wired to
 * a broadcasting backend yet, "no realtime" is the correct quiet
 * fallback; subscribers (`useEcho`, `usePrimeConnectRooms`, etc.)
 * already null-check `window.Echo`.
 *
 * The "skipping" notice only logs in dev — a developer running
 * locally without a broadcast backend should see why realtime is
 * off, but a production console shouldn't carry a standing info
 * line. Set PUSHER_APP_KEY (+ cluster/host) on the environment to
 * turn realtime on; nothing else needs to change.
 */
interface EchoConfig {
    host: string;
    key: string;
    cluster: string;
    /** Port the WS server listens on. 443 for Cloud/managed, 6001 for local Reverb. */
    port?: number;
    /** 'https' → wss + forceTLS; 'http' → ws (local dev only). */
    scheme?: string;
}

export function initEcho(props: { echo: EchoConfig }): PusherEcho | null {
    if (!props.echo.key) {
        if (import.meta.env.DEV) {
            // eslint-disable-next-line no-console
            console.info('[echo] Pusher key not set — skipping realtime WebSocket init.');
        }
        return null;
    }

    window.Pusher = Pusher;

    // Cloud Reverb is wss on 443; a local Reverb/Soketi is ws on 6001.
    // The server tells us which via scheme + port; we don't guess from
    // whether `host` is set (the old `forceTLS: !host` was backwards —
    // a populated Cloud host needs TLS, not the absence of it).
    const useTLS = (props.echo.scheme ?? 'https') === 'https';
    const port = props.echo.port ?? (useTLS ? 443 : 6001);

    // /broadcasting/auth sits behind the `web` group → CSRF-verified.
    // laravel-echo usually auto-reads the csrf-token meta tag, but pass
    // it explicitly so a private-channel subscribe can never 419 on a
    // missing header.
    const csrf = document.head
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: props.echo.key,
        cluster: props.echo.cluster,
        wsHost: props.echo.host || undefined,
        wsPort: port,
        wssPort: port,
        forceTLS: useTLS,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });

    return window.Echo;
}
