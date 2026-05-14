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
export function initEcho(props: { echo: { host: string; key: string; cluster: string } }): PusherEcho | null {
    if (!props.echo.key) {
        if (import.meta.env.DEV) {
            // eslint-disable-next-line no-console
            console.info('[echo] Pusher key not set — skipping realtime WebSocket init.');
        }
        return null;
    }

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: props.echo.key,
        cluster: props.echo.cluster,
        wsHost: props.echo.host || undefined,
        wsPort: 6001,
        wssPort: 6001,
        forceTLS: !props.echo.host, // dev Soketi over plain HTTP; managed Pusher over WSS
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
    });

    return window.Echo;
}
