<script setup lang="ts">
import { computed } from 'vue';
import type { AgentStatusValue } from '@/types/api';

interface AgentSnapshot {
    agent_id: string;
    status: AgentStatusValue;
}

const props = defineProps<{
    agents: Record<string, AgentSnapshot>;
    callsToday?: number;
    revenueToday?: number;
    conversionPct?: number;
}>();

const stats = computed(() => {
    const all = Object.values(props.agents);
    const counts = {
        available: 0,
        on_call: 0,
        wrap_up: 0,
        on_break: 0,
        offline: 0,
    } as Record<AgentStatusValue, number>;

    for (const a of all) {
        counts[a.status] = (counts[a.status] ?? 0) + 1;
    }
    return counts;
});

const cards = computed(() => [
    { label: 'Available', value: stats.value.available, color: 'text-emerald-300' },
    { label: 'On call', value: stats.value.on_call, color: 'text-rose-300' },
    { label: 'Wrap-up', value: stats.value.wrap_up, color: 'text-amber-300' },
    { label: 'On break', value: stats.value.on_break, color: 'text-slate-400' },
    { label: 'Offline', value: stats.value.offline, color: 'text-slate-500' },
    { label: 'Calls today', value: props.callsToday ?? 0, color: 'text-slate-200' },
    { label: 'Revenue today', value: `$${(props.revenueToday ?? 0).toLocaleString()}`, color: 'text-emerald-300' },
    { label: 'Conversion', value: `${props.conversionPct ?? 0}%`, color: 'text-emerald-300' },
]);
</script>

<template>
    <div class="grid grid-cols-4 gap-3 lg:grid-cols-8">
        <div
            v-for="card in cards"
            :key="card.label"
            class="dialer-panel px-3 py-2"
        >
            <div class="text-[10px] uppercase tracking-wider text-slate-500">{{ card.label }}</div>
            <div class="mt-0.5 text-xl font-semibold" :class="card.color">{{ card.value }}</div>
        </div>
    </div>
</template>
