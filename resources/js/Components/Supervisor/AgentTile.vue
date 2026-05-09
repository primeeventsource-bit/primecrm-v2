<script setup lang="ts">
import { computed } from 'vue';
import type { AgentStatusValue } from '@/types/api';

const props = defineProps<{
    agentId: string;
    name?: string;
    status: AgentStatusValue;
    callId?: string | null;
}>();

const emit = defineEmits<{ (e: 'whisper', callId: string): void; (e: 'kill', callId: string): void }>();

const tileClass = computed(() => {
    switch (props.status) {
        case 'available':
            return 'border-emerald-500/40 bg-emerald-500/5';
        case 'on_call':
            return 'border-rose-500/40 bg-rose-500/5 animate-pulse';
        case 'wrap_up':
            return 'border-amber-500/40 bg-amber-500/5';
        case 'on_break':
            return 'border-slate-500/40 bg-slate-500/5';
        default:
            return 'border-slate-700 bg-slate-900/40';
    }
});

const dotClass = computed(() => {
    switch (props.status) {
        case 'available':
            return 'bg-emerald-500';
        case 'on_call':
            return 'bg-rose-500';
        case 'wrap_up':
            return 'bg-amber-500';
        case 'on_break':
            return 'bg-slate-400';
        default:
            return 'bg-slate-600';
    }
});
</script>

<template>
    <div class="relative rounded-md border p-3" :class="tileClass">
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-1.5">
                    <span class="inline-block h-2 w-2 rounded-full" :class="dotClass"></span>
                    <span class="text-sm font-medium text-slate-100">{{ name ?? agentId.slice(0, 8) }}</span>
                </div>
                <div class="mt-0.5 text-xs text-slate-400">{{ status.replace(/_/g, ' ') }}</div>
            </div>
            <div v-if="status === 'on_call' && callId" class="flex gap-1">
                <button
                    class="rounded px-2 py-0.5 text-[10px] uppercase tracking-wider text-amber-300 ring-1 ring-amber-500/40 hover:bg-amber-500/10"
                    @click="emit('whisper', callId)"
                >
                    whisper
                </button>
                <button
                    class="rounded px-2 py-0.5 text-[10px] uppercase tracking-wider text-rose-300 ring-1 ring-rose-500/40 hover:bg-rose-500/10"
                    @click="emit('kill', callId)"
                >
                    kill
                </button>
            </div>
        </div>
    </div>
</template>
