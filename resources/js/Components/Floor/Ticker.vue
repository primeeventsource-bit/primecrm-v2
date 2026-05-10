<script setup lang="ts">
/**
 * Live activity ticker — borrows the trading-floor metaphor that
 * sales floors already understand. Items are kept short on purpose:
 * actor + verb + amount + age. "Marcus closed $3.2k · 30s ago".
 *
 * Source of truth: the parent passes events in. When the backend
 * stream lands (websockets / SSE) the parent will push new ones onto
 * the array; this component never owns the data.
 *
 * Empty state is intentionally voiced — see Dashboard.vue's copy.
 */
import { computed } from 'vue';

export interface TickerEvent {
    id: string;
    actor: string;
    verb: string;        // closed / transferred / verified / charged / lost
    amount?: number | null;
    at: string;          // ISO timestamp
}

const props = withDefaults(defineProps<{
    events: TickerEvent[];
    emptyHint?: string;
}>(), {
    emptyHint: 'Quiet on the floor. New activity will land here as it happens.',
});

function fmtAmount(n: number | null | undefined): string {
    if (n == null) return '';
    if (n >= 1000) return `$${(n / 1000).toFixed(1)}k`;
    return `$${Math.round(n).toLocaleString('en-US')}`;
}

function fmtAge(at: string): string {
    const ms = Date.now() - new Date(at).getTime();
    if (ms < 60_000) return `${Math.max(0, Math.round(ms / 1000))}s ago`;
    if (ms < 3_600_000) return `${Math.floor(ms / 60_000)}m ago`;
    if (ms < 86_400_000) return `${Math.floor(ms / 3_600_000)}h ago`;
    return `${Math.floor(ms / 86_400_000)}d ago`;
}

function verbColor(verb: string): string {
    if (verb === 'closed' || verb === 'charged' || verb === 'verified') return 'text-floor-win';
    if (verb === 'lost' || verb === 'dnc') return 'text-floor-lose';
    if (verb === 'transferred') return 'text-floor-info';
    return 'text-deck-soft';
}

const items = computed(() => props.events.slice(0, 30));
</script>

<template>
    <div class="deck-card p-0 overflow-hidden flex flex-col">
        <div class="flex items-center justify-between border-b border-deck-line px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="deck-dot-live"></span>
                <span class="text-[10px] font-mono uppercase tracking-[0.18em] text-deck-soft">Floor activity</span>
            </div>
            <span class="text-[10px] font-mono text-deck-dim">{{ items.length }} events</span>
        </div>

        <div v-if="items.length === 0" class="flex-1 px-3 py-8 text-center text-sm text-deck-dim italic">
            {{ emptyHint }}
        </div>

        <ul v-else class="flex-1 overflow-y-auto divide-y divide-deck-line/50">
            <li v-for="ev in items" :key="ev.id"
                class="flex items-center justify-between px-3 py-2 text-sm hover:bg-deck-raised/40">
                <div class="min-w-0 truncate">
                    <span class="font-medium text-deck-text">{{ ev.actor }}</span>
                    <span :class="verbColor(ev.verb)" class="ml-1.5">{{ ev.verb }}</span>
                    <span v-if="ev.amount" class="ml-1 font-mono tabular-nums text-deck-text">{{ fmtAmount(ev.amount) }}</span>
                </div>
                <span class="ml-3 shrink-0 font-mono text-[11px] tabular-nums text-deck-dim">{{ fmtAge(ev.at) }}</span>
            </li>
        </ul>
    </div>
</template>
