<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import SideNav from '@/Components/SideNav.vue';
import AgentStatusPill from '@/Components/AgentStatusPill.vue';
import type { PageProps } from '@/types/api';

defineProps<{ title?: string }>();

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);

// Live clock in the header — small thing, but a visible heartbeat
// reminds floor managers the deck is alive and synced.
const now = ref(new Date());
let tick: number | undefined;
onMounted(() => {
    tick = window.setInterval(() => (now.value = new Date()), 1000);
});
onUnmounted(() => {
    if (tick !== undefined) window.clearInterval(tick);
});
const clock = computed(() =>
    now.value.toLocaleTimeString('en-US', { hour12: false })
);
const dateLabel = computed(() =>
    now.value.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
);
</script>

<template>
    <Head :title="title" />

    <div class="flex h-screen overflow-hidden bg-deck-bg text-deck-text">
        <SideNav />

        <main class="flex flex-1 flex-col overflow-hidden">
            <header class="flex items-center justify-between border-b border-deck-line bg-deck-surface px-6 py-3">
                <div class="flex items-center gap-4">
                    <h1 class="text-lg font-semibold text-deck-text">{{ title ?? 'Floor OS' }}</h1>
                    <span class="hidden sm:flex items-center gap-2 text-xs font-mono tabular-nums text-deck-dim">
                        <span class="deck-dot-live"></span>
                        <span class="text-deck-soft">{{ clock }}</span>
                        <span>· {{ dateLabel }}</span>
                    </span>
                </div>
                <div class="flex items-center gap-4">
                    <AgentStatusPill v-if="user" />
                    <div v-if="user" class="text-right">
                        <div class="text-sm font-medium text-deck-text">{{ user.name }}</div>
                        <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                            {{ user.role.replace(/_/g, ' ') }}
                        </div>
                    </div>
                </div>
            </header>

            <!-- Flash messaging — minimal, semantic edge -->
            <div v-if="flash.success" class="border-b border-floor-win/30 bg-floor-win/10 px-6 py-2 text-sm text-floor-win">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="border-b border-floor-lose/30 bg-floor-lose/10 px-6 py-2 text-sm text-floor-lose">
                {{ flash.error }}
            </div>

            <div class="flex-1 overflow-auto">
                <slot />
            </div>
        </main>
    </div>
</template>
