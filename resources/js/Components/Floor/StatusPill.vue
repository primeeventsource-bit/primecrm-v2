<script setup lang="ts">
/**
 * Agent floor-status pill — ON CALL / IDLE / WRAP, etc.
 *
 * Single source of truth for that status visual so the dashboard,
 * leaderboard, dialer, and supervisor war room all read identically.
 *
 * `since` is an ISO timestamp of when the agent entered this status;
 * we render an idle counter ("IDLE 2m") for IDLE specifically because
 * that's the one a floor manager needs to glance-and-act on.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';

type FloorStatus = 'on_call' | 'idle' | 'wrap' | 'offline';

const props = withDefaults(defineProps<{
    status: FloorStatus;
    since?: string | null;
}>(), {
    since: null,
});

const now = ref(Date.now());
let tick: number | undefined;
onMounted(() => {
    tick = window.setInterval(() => (now.value = Date.now()), 30_000);
});
onUnmounted(() => {
    if (tick !== undefined) window.clearInterval(tick);
});

const elapsedLabel = computed(() => {
    if (!props.since) return '';
    const ms = now.value - new Date(props.since).getTime();
    if (ms < 60_000) return `${Math.max(0, Math.round(ms / 1000))}s`;
    if (ms < 3_600_000) return `${Math.floor(ms / 60_000)}m`;
    return `${Math.floor(ms / 3_600_000)}h`;
});

const cfg = computed(() => {
    switch (props.status) {
        case 'on_call':
            return { cls: 'pill-on-call', label: 'ON CALL', dot: true, showElapsed: false };
        case 'idle':
            return { cls: 'pill-idle', label: 'IDLE', dot: false, showElapsed: true };
        case 'wrap':
            return { cls: 'pill-wrap', label: 'WRAP', dot: false, showElapsed: true };
        case 'offline':
        default:
            return { cls: 'pill-idle', label: 'OFFLINE', dot: false, showElapsed: false };
    }
});
</script>

<template>
    <span :class="cfg.cls" class="font-mono">
        <span v-if="cfg.dot" class="deck-dot bg-floor-onCall mr-1.5 animate-pulse-dot"></span>
        {{ cfg.label }}<span v-if="cfg.showElapsed && elapsedLabel" class="ml-1 opacity-70">{{ elapsedLabel }}</span>
    </span>
</template>
