import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo;
    }
}

/**
 * Lazily wire up Echo. Called from app.ts with the per-deploy config that
 * Laravel injects into Inertia's initialPage props (see HandleInertiaRequests).
 *
 * Keeping this in a function (rather than at module scope) means tests
 * that don't need WebSockets can stub or skip the call.
 */
export function initEcho(props: { echo: { host: string; key: string; cluster: string } }): Echo {
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
