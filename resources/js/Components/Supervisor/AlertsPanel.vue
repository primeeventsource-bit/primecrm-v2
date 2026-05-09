<script setup lang="ts">
interface AlertLog {
    at: string;
    type: 'dial_skipped';
    reason: string;
    rejection_code: string | null;
    lead_id: string;
}

defineProps<{ alerts: AlertLog[] }>();

function timeShort(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-US', { hour12: false });
}
</script>

<template>
    <section class="dialer-panel flex h-full flex-col">
        <header class="border-b border-slate-700/40 px-4 py-2">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Compliance alerts</h2>
        </header>
        <div class="flex-1 overflow-y-auto">
            <div v-if="alerts.length === 0" class="p-4 text-center text-xs text-slate-500">
                No rejections yet.
            </div>
            <ul class="divide-y divide-slate-800">
                <li v-for="(alert, idx) in alerts" :key="idx" class="px-4 py-2 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-slate-500">{{ timeShort(alert.at) }}</span>
                        <span class="rounded bg-rose-500/15 px-1.5 py-0.5 font-mono uppercase text-rose-300">
                            {{ alert.rejection_code ?? 'rejected' }}
                        </span>
                    </div>
                    <div class="mt-0.5 text-slate-300">{{ alert.reason }}</div>
                    <div class="text-slate-500">lead {{ alert.lead_id.slice(0, 8) }}</div>
                </li>
            </ul>
        </div>
    </section>
</template>
