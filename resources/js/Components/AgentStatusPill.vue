<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';
import type { AgentStatusValue } from '@/types/api';

const status = ref<AgentStatusValue>('offline');

const colorClass = computed(() => {
    switch (status.value) {
        case 'available':
            return 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/30';
        case 'on_call':
            return 'bg-rose-500/15 text-rose-700 ring-rose-500/30 animate-pulse';
        case 'wrap_up':
            return 'bg-amber-500/15 text-amber-700 ring-amber-500/30';
        case 'on_break':
            return 'bg-slate-500/15 text-slate-700 ring-slate-500/30';
        default:
            return 'bg-slate-300/30 text-slate-500 ring-slate-400/20';
    }
});

const label = computed(() => {
    return status.value.replace(/_/g, ' ');
});

let heartbeatHandle: number | undefined;

async function loadStatus() {
    try {
        const { data } = await axios.get('/api/agent-status/me');
        status.value = (data.data?.status ?? data.status) as AgentStatusValue;
    } catch {
        status.value = 'offline';
    }
}

async function setStatus(next: AgentStatusValue) {
    await axios.post('/api/agent-status', { status: next });
    status.value = next;
}

onMounted(() => {
    void loadStatus();
    // Heartbeat every 20s while page is open
    heartbeatHandle = window.setInterval(() => {
        void axios.post('/api/agent-status/heartbeat').catch(() => {});
    }, 20_000);
});

onUnmounted(() => {
    if (heartbeatHandle !== undefined) clearInterval(heartbeatHandle);
});

defineExpose({ setStatus, status });
</script>

<template>
    <div class="flex items-center gap-2">
        <span
            class="pill ring-1 ring-inset"
            :class="colorClass"
        >
            <span class="mr-1.5 inline-block h-2 w-2 rounded-full" :class="
                status === 'available'   ? 'bg-emerald-500' :
                status === 'on_call'     ? 'bg-rose-500' :
                status === 'wrap_up'     ? 'bg-amber-500' :
                status === 'on_break'    ? 'bg-slate-500' :
                                           'bg-slate-400'
            "></span>
            {{ label }}
        </span>
        <select
            class="rounded-md border-slate-300 text-xs"
            :value="status"
            @change="setStatus(($event.target as HTMLSelectElement).value as AgentStatusValue)"
        >
            <option value="available">Available</option>
            <option value="wrap_up">Wrap-up</option>
            <option value="on_break">Break</option>
            <option value="offline">Offline</option>
        </select>
    </div>
</template>
