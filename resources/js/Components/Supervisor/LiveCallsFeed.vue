<script setup lang="ts">
interface CallEventLog {
    at: string;
    event: string;
    call_id: string;
    agent_id: string | null;
    payload: Record<string, unknown>;
}

defineProps<{ feed: CallEventLog[] }>();

function timeShort(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-US', { hour12: false });
}

function eventColor(event: string): string {
    if (event === 'call.initiated') return 'text-amber-400';
    if (event === 'call.connected') return 'text-emerald-400';
    if (event === 'call.ended') return 'text-slate-400';
    return 'text-slate-300';
}
</script>

<template>
    <section class="dialer-panel flex h-full flex-col">
        <header class="border-b border-slate-700/40 px-4 py-2">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Live calls feed</h2>
        </header>
        <div class="flex-1 overflow-y-auto">
            <div v-if="feed.length === 0" class="p-4 text-center text-xs text-slate-500">
                Waiting for events…
            </div>
            <ul class="divide-y divide-slate-800">
                <li v-for="(item, idx) in feed" :key="idx" class="px-4 py-2 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-slate-500">{{ timeShort(item.at) }}</span>
                        <span class="font-mono uppercase" :class="eventColor(item.event)">{{ item.event }}</span>
                    </div>
                    <div class="mt-0.5 text-slate-300">
                        agent <span class="font-mono">{{ item.agent_id?.slice(0, 8) ?? '—' }}</span>
                        → call <span class="font-mono">{{ item.call_id.slice(0, 8) }}</span>
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
