<script setup lang="ts">
import { computed } from 'vue';
import type { Lead } from '@/types/api';

const props = defineProps<{ lead: Lead | null }>();

/**
 * Dynamic script. Phase 1 selects a template by lead.source; the
 * dialer's pitch_data on the campaign can override per-campaign in the
 * future. Keeping the resolution function in-component for now —
 * promote to a composable when more inputs land (sentiment, prior
 * disposition, etc.).
 */
const sections = computed(() => {
    const name = props.lead?.first_name || 'there';
    const resort = props.lead?.resort_interest || 'a great resort property';
    const source = props.lead?.source ?? 'unknown';

    if (source === 'inbound_call') {
        return [
            { title: 'Inbound greet', body: `Hi ${name}, thanks for reaching out about ${resort}!` },
            { title: 'Qualify', body: 'Let me ask a few quick questions to find the best week for you…' },
            { title: 'Pitch', body: `Based on what you said, I think the ${resort} mid-week package fits.` },
            { title: 'Close', body: 'I can hold a unit for the next 30 minutes while we finalize. Sound good?' },
        ];
    }

    if (source === 'referral') {
        return [
            { title: 'Open (warm)', body: `Hi ${name}, I'm calling because [referrer] mentioned you'd be interested in ${resort}.` },
            { title: 'Discovery', body: 'How often do you travel? Are you flexible on dates?' },
            { title: 'Pitch', body: `Our ${resort} units run $1,500–$2,500/week. We have May availability.` },
            { title: 'Objection: price', body: '"At first glance the price seems high — but compared to renting through a portal, you save 40% on a unit twice the size."' },
            { title: 'Close', body: 'I can hold the unit for 30 min while we get the deposit set up. Want me to do that?' },
        ];
    }

    return [
        { title: 'Cold open', body: `Hi ${name}, this is [agent] from Prime Vacations. Did I catch you at a bad time?` },
        { title: 'Hook', body: `I'm reaching out because we just released some last-minute weeks at ${resort}.` },
        { title: 'Discovery', body: 'When were you thinking of traveling? Any specific resort in mind?' },
        { title: 'Pitch', body: 'Let me walk you through one option that I think you\'ll love…' },
        { title: 'Close', body: 'Ready to lock this in? I can hold the unit for the next 30 minutes.' },
    ];
});
</script>

<template>
    <section class="dialer-panel flex flex-col gap-3 p-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Script</h2>
            <span v-if="lead" class="text-xs text-slate-500">via {{ lead.source }}</span>
        </header>

        <div class="space-y-3 overflow-y-auto pr-1">
            <div
                v-for="section in sections"
                :key="section.title"
                class="rounded-md border border-slate-700/60 bg-slate-900/40 px-3 py-2"
            >
                <div class="mb-1 text-[11px] uppercase tracking-wider text-slate-400">{{ section.title }}</div>
                <p class="text-sm text-slate-100">{{ section.body }}</p>
            </div>
        </div>
    </section>
</template>
