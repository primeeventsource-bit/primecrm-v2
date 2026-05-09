import { computed, onUnmounted, ref, watch } from 'vue';

/**
 * Drives the giant "00:23" timer on the call control panel.
 *
 * Pass a reactive ref of the call's `answered_at` ISO string. The timer
 * resets to 00:00 when answered_at flips, ticks every second until the
 * call ends, and stops cleanly when the ref clears.
 */
export function useCallTimer(answeredAtRef: { value: string | null | undefined }) {
    const now = ref<number>(Date.now());
    let handle: number | undefined;

    function start(): void {
        stop();
        handle = window.setInterval(() => {
            now.value = Date.now();
        }, 1000);
    }

    function stop(): void {
        if (handle !== undefined) {
            clearInterval(handle);
            handle = undefined;
        }
    }

    watch(
        () => answeredAtRef.value,
        (val) => {
            if (val) {
                now.value = Date.now();
                start();
            } else {
                stop();
            }
        },
        { immediate: true },
    );

    onUnmounted(stop);

    const elapsedSeconds = computed<number>(() => {
        if (!answeredAtRef.value) return 0;
        const answeredMs = Date.parse(answeredAtRef.value);
        return Math.max(0, Math.floor((now.value - answeredMs) / 1000));
    });

    const display = computed<string>(() => {
        const total = elapsedSeconds.value;
        const m = Math.floor(total / 60).toString().padStart(2, '0');
        const s = (total % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    });

    return { elapsedSeconds, display };
}
