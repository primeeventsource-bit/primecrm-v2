<script setup lang="ts">
import { computed } from 'vue';
import type { DialSession } from '@/types/api';

const props = defineProps<{
    session: DialSession | null;
    onStart: () => void;
    onPause: () => void;
    onResume: () => void;
    onStop: () => void;
}>();

const statusBadge = computed(() => {
    const s = props.session?.status;
    if (s === 'active') return { label: 'Session active', cls: 'bg-emerald-500/20 text-emerald-300 ring-emerald-500/40' };
    if (s === 'paused') return { label: 'Paused', cls: 'bg-amber-500/20 text-amber-300 ring-amber-500/40' };
    return { label: 'No session', cls: 'bg-slate-500/20 text-slate-400 ring-slate-500/30' };
});
</script>

<template>
    <div class="flex items-center justify-between gap-4 border-b border-slate-700/40 bg-dialer-panel px-5 py-3">
        <div class="flex items-center gap-3">
            <span class="pill ring-1 ring-inset" :class="statusBadge.cls">{{ statusBadge.label }}</span>
            <div v-if="session" class="text-xs text-slate-400">
                <span class="mr-3">Mode: <b class="text-slate-200">{{ session.mode }}</b></span>
                <span class="mr-3">Initiated: <b class="text-slate-200">{{ session.calls_initiated }}</b></span>
                <span class="mr-3">Connected: <b class="text-slate-200">{{ session.calls_connected }}</b></span>
                <span class="mr-3">Talk time: <b class="text-slate-200">{{ Math.floor(session.total_talk_seconds / 60) }}m</b></span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button v-if="!session" class="btn-success" @click="onStart">Start session</button>
            <button v-else-if="session.status === 'active'" class="btn-ghost" @click="onPause">Pause</button>
            <button v-else-if="session.status === 'paused'" class="btn-success" @click="onResume">Resume</button>
            <button v-if="session && session.status !== 'ended'" class="btn-danger" @click="onStop">Stop</button>
        </div>
    </div>
</template>
