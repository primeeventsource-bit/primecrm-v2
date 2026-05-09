<script setup lang="ts">
import { computed } from 'vue';
import type { Lead } from '@/types/api';

const props = defineProps<{ lead: Lead | null }>();

const priorityClass = computed(() => {
    switch (props.lead?.priority) {
        case 'hot':
            return 'bg-rose-500/20 text-rose-300 ring-rose-500/40';
        case 'high':
            return 'bg-amber-500/20 text-amber-300 ring-amber-500/40';
        case 'normal':
            return 'bg-slate-500/20 text-slate-300 ring-slate-500/40';
        default:
            return 'bg-slate-500/20 text-slate-400 ring-slate-500/30';
    }
});
</script>

<template>
    <section class="dialer-panel flex flex-col gap-4 p-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Lead</h2>
            <div v-if="lead" class="flex items-center gap-2">
                <span class="pill ring-1 ring-inset" :class="priorityClass">
                    {{ lead.priority }}
                </span>
                <span class="text-xs text-slate-400">Score: {{ lead.score }}</span>
            </div>
        </header>

        <div v-if="!lead" class="rounded-md border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
            No lead loaded. Start a session or pick from the queue.
        </div>

        <div v-else class="space-y-3">
            <div>
                <div class="text-xl font-semibold text-white">{{ lead.full_name || '(no name)' }}</div>
                <div class="font-mono text-2xl text-emerald-400">{{ lead.phone }}</div>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div>
                    <dt class="text-xs uppercase text-slate-500">Email</dt>
                    <dd class="text-slate-200">{{ lead.email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Source</dt>
                    <dd class="text-slate-200">{{ lead.source }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Resort interest</dt>
                    <dd class="text-slate-200">{{ lead.resort_interest ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Est. value</dt>
                    <dd class="text-slate-200">{{ lead.estimated_value ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Attempts</dt>
                    <dd class="text-slate-200">{{ lead.contact_attempts }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Consent</dt>
                    <dd>
                        <span
                            class="pill ring-1 ring-inset"
                            :class="
                                lead.has_express_consent
                                    ? 'bg-emerald-500/20 text-emerald-300 ring-emerald-500/40'
                                    : 'bg-slate-500/20 text-slate-400 ring-slate-500/30'
                            "
                        >
                            {{ lead.has_express_consent ? 'on file' : 'none' }}
                        </span>
                    </dd>
                </div>
            </dl>

            <div v-if="lead.is_on_dnc" class="rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-300">
                ⚠ This lead is flagged on DNC. The dialer will refuse outbound calls.
            </div>
        </div>
    </section>
</template>
