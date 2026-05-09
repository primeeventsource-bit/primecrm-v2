<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Deal {
    id: string;
    lead_id: string;
    agent_id: string;
    stage: string;
    total_value: string;
    payable_amount: string;
    currency: string;
}

const stages = ['new', 'contacted', 'qualified', 'pitch_presented', 'negotiating', 'closed_won', 'closed_lost'] as const;
const deals = ref<Deal[]>([]);
const loading = ref(false);

async function loadDeals(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: Deal[] }>('/api/deals?per_page=200');
        deals.value = data.data;
    } finally {
        loading.value = false;
    }
}

const byStage = computed(() => {
    const buckets: Record<string, Deal[]> = {};
    for (const stage of stages) buckets[stage] = [];
    for (const d of deals.value) {
        (buckets[d.stage] ?? buckets.new).push(d);
    }
    return buckets;
});

async function advance(deal: Deal, toStage: string): Promise<void> {
    await axios.post(`/api/deals/${deal.id}/advance-stage`, { stage: toStage });
    await loadDeals();
}

function nextStage(stage: string): string | null {
    const idx = stages.indexOf(stage as (typeof stages)[number]);
    if (idx === -1 || idx >= stages.length - 2) return null;
    return stages[idx + 1];
}

onMounted(loadDeals);
</script>

<template>
    <AppLayout title="Pipeline">
        <div class="flex h-full flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">Pipeline</h2>
                <button class="btn-ghost" @click="loadDeals">Refresh</button>
            </div>

            <div class="flex flex-1 gap-3 overflow-x-auto pb-4">
                <section
                    v-for="stage in stages"
                    :key="stage"
                    class="flex w-72 shrink-0 flex-col rounded-lg bg-slate-100"
                >
                    <header class="border-b border-slate-200 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-600">
                            {{ stage.replace(/_/g, ' ') }}
                        </div>
                        <div class="text-[11px] text-slate-500">{{ byStage[stage].length }} deals</div>
                    </header>
                    <div class="flex-1 space-y-2 overflow-y-auto p-2">
                        <article
                            v-for="d in byStage[stage]"
                            :key="d.id"
                            class="rounded-md border border-slate-200 bg-white p-3 shadow-sm"
                        >
                            <div class="text-xs text-slate-500">Deal {{ d.id.slice(0, 8) }}</div>
                            <div class="text-sm font-medium text-slate-900">${{ d.payable_amount }}</div>
                            <button
                                v-if="nextStage(stage)"
                                class="mt-2 w-full rounded bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                @click="advance(d, nextStage(stage)!)"
                            >
                                → {{ nextStage(stage)?.replace(/_/g, ' ') }}
                            </button>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
