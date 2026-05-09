import { onBeforeUnmount, onMounted } from 'vue';
import type Echo from 'laravel-echo';

type Listener = (payload: Record<string, unknown>) => void;

interface Subscription {
    channel: string;
    event: string;
    callback: Listener;
}

/**
 * Subscribe to one or more Echo events for the lifetime of the calling
 * component. Tear-down on unmount happens automatically — no leaks if
 * the agent navigates away mid-session.
 */
export function useEcho() {
    const subs: Subscription[] = [];

    function on(channel: string, event: string, callback: Listener): void {
        subs.push({ channel, event, callback });
    }

    onMounted(() => {
        const echo: Echo | undefined = window.Echo;
        if (!echo) return;

        for (const sub of subs) {
            echo.private(sub.channel).listen(`.${sub.event}`, sub.callback);
        }
    });

    onBeforeUnmount(() => {
        const echo: Echo | undefined = window.Echo;
        if (!echo) return;

        const channels = new Set(subs.map((s) => s.channel));
        for (const ch of channels) {
            echo.leave(ch);
        }
    });

    return { on };
}
