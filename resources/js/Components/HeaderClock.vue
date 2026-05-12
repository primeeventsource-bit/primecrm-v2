<script setup lang="ts">
/**
 * Standalone live clock for AppLayout's header.
 *
 * Lives in its own component on purpose: when the clock's `now` ref
 * was inside AppLayout, every 1-second tick re-rendered the WHOLE
 * layout, which invalidated the page slot underneath. Any modal +
 * form mounted in the slot (e.g. CreateListingForm) would re-render
 * every second mid-keystroke — the user saw it as the popup
 * flickering and inputs refusing characters.
 *
 * Keeping the interval here means re-renders stay scoped to this
 * 60-pixel chunk of the header. Identical visual; no layout side-
 * effects.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';

const now = ref(new Date());
let tick: number | undefined;

onMounted(() => {
    tick = window.setInterval(() => (now.value = new Date()), 1000);
});
onUnmounted(() => {
    if (tick !== undefined) window.clearInterval(tick);
});

const clock = computed(() =>
    now.value.toLocaleTimeString('en-US', { hour12: false }),
);
const dateLabel = computed(() =>
    now.value.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }),
);
</script>

<template>
    <span class="hidden sm:flex items-center gap-2 text-xs font-mono tabular-nums text-deck-dim">
        <span class="deck-dot-live"></span>
        <span class="text-deck-soft">{{ clock }}</span>
        <span>· {{ dateLabel }}</span>
    </span>
</template>
