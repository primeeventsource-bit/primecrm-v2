<script setup lang="ts">
import { computed } from 'vue';
import type { Call } from '@/types/api';
import { useCallTimer } from '@/Composables/useCallTimer';

const props = defineProps<{ call: Call | null }>();
const emit = defineEmits<{
    (e: 'end'): void;
    (e: 'mute'): void;
    (e: 'hold'): void;
    (e: 'transfer'): void;
}>();

const answeredAt = computed(() => props.call?.answered_at ?? null);
const { display } = useCallTimer(answeredAt);

const statusLabel = computed(() => {
    const s = props.call?.status;
    switch (s) {
        case 'queued':
            return 'QUEUED';
        case 'initiated':
            return 'DIALING';
        case 'ringing':
            return 'RINGING';
        case 'in_progress':
            return 'CONNECTED';
        case 'completed':
            return 'COMPLETED';
        case 'busy':
            return 'BUSY';
        case 'no_answer':
            return 'NO ANSWER';
        case 'failed':
            return 'FAILED';
        case 'canceled':
            return 'CANCELED';
        default:
            return 'IDLE';
    }
});

const statusColor = computed(() => {
    const s = props.call?.status;
    if (s === 'in_progress') return 'text-emerald-400';
    if (s === 'ringing' || s === 'initiated') return 'text-amber-400 animate-pulse-call';
    if (s === 'completed') return 'text-slate-400';
    if (s === 'busy' || s === 'no_answer' || s === 'failed' || s === 'canceled') return 'text-rose-400';
    return 'text-slate-500';
});

const showControls = computed(() => {
    const s = props.call?.status;
    return s === 'initiated' || s === 'ringing' || s === 'in_progress';
});
</script>

<template>
    <section class="dialer-panel flex flex-col gap-4 p-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Call control</h2>
        </header>

        <div class="flex flex-col items-center gap-2 py-2">
            <div class="font-mono text-5xl font-semibold tracking-tight text-white">{{ display }}</div>
            <div class="font-mono text-sm font-bold tracking-wider" :class="statusColor">
                {{ statusLabel }}
            </div>
        </div>

        <div v-if="showControls" class="grid grid-cols-3 gap-2">
            <button class="btn-ghost" @click="emit('mute')">Mute</button>
            <button class="btn-ghost" @click="emit('hold')">Hold</button>
            <button class="btn-ghost" @click="emit('transfer')">Transfer</button>
        </div>

        <button
            v-if="showControls"
            class="btn-danger w-full"
            @click="emit('end')"
        >
            End Call
        </button>
    </section>
</template>
