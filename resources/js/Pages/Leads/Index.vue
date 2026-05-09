<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import CreateLeadForm from '@/Components/Leads/CreateLeadForm.vue';
import type { Lead } from '@/types/api';

interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
}

const leads = ref<Lead[]>([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const loading = ref(false);
const search = ref('');
const status = ref('');
const minScore = ref<number | null>(null);
const createOpen = ref(false);

function onLeadCreated(): void {
    createOpen.value = false;
    page.value = 1;
    void load();
}

let searchTimer: number | undefined;

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string | number> = {
            page: page.value,
            per_page: perPage.value,
            sort: 'score',
            direction: 'desc',
        };
        if (search.value) params.q = search.value;
        if (status.value) params.status = status.value;
        if (minScore.value) params.min_score = minScore.value;

        const { data } = await axios.get<Paginated<Lead>>('/api/leads', { params });
        leads.value = data.data;
        total.value = data.meta.total;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        page.value = 1;
        void load();
    }, 250);
});

watch([status, minScore, page], () => void load());

function priorityClass(p: string | null): string {
    return {
        hot: 'bg-rose-100 text-rose-700',
        high: 'bg-amber-100 text-amber-700',
        normal: 'bg-slate-100 text-slate-700',
        low: 'bg-slate-100 text-slate-500',
    }[p ?? 'normal'] ?? 'bg-slate-100 text-slate-700';
}

const lastPage = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

onMounted(load);
</script>

<template>
    <AppLayout title="Leads">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Leads</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ total }} total · sorted by score</p>
                </div>
                <button class="btn-primary" @click="createOpen = true">
                    + Add lead
                </button>
            </div>

            <Modal :open="createOpen" title="Add a lead" @close="createOpen = false">
                <CreateLeadForm @created="onLeadCreated" @cancel="createOpen = false" />
            </Modal>

            <section class="panel mb-4 grid grid-cols-1 gap-3 p-3 md:grid-cols-4">
                <div>
                    <label class="label">Search</label>
                    <input v-model="search" type="text" placeholder="name / email / phone" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Status</label>
                    <select v-model="status" class="input mt-1">
                        <option value="">Any</option>
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="qualified">Qualified</option>
                        <option value="pitch_presented">Pitch presented</option>
                        <option value="negotiating">Negotiating</option>
                        <option value="closed_won">Closed won</option>
                        <option value="closed_lost">Closed lost</option>
                    </select>
                </div>
                <div>
                    <label class="label">Min score</label>
                    <input v-model.number="minScore" type="number" min="0" max="1000" placeholder="0" class="input mt-1" />
                </div>
                <div class="flex items-end">
                    <button class="btn-ghost w-full" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
                </div>
            </section>

            <div class="panel overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Name</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Phone</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Source</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Status</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Priority</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wider text-slate-500">Score</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!loading && leads.length === 0">
                            <td colspan="7" class="px-3 py-12 text-center text-sm text-slate-500">
                                No leads yet. Run <code>DemoSeeder</code> or POST to <code>/api/leads/import</code>.
                            </td>
                        </tr>
                        <tr v-for="l in leads" :key="l.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-sm text-slate-900">{{ l.full_name || '(unnamed)' }}<div v-if="l.email" class="text-xs text-slate-500">{{ l.email }}</div></td>
                            <td class="px-3 py-2 text-sm font-mono text-slate-700">{{ l.phone }}</td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ l.source }}</td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ l.status?.replace(/_/g, ' ') ?? '—' }}</td>
                            <td class="px-3 py-2"><span class="pill" :class="priorityClass(l.priority)">{{ l.priority ?? 'normal' }}</span></td>
                            <td class="px-3 py-2 text-right text-sm font-semibold text-slate-900">{{ l.score }}</td>
                            <td class="px-3 py-2 text-xs">
                                <span v-if="l.is_on_dnc" class="pill bg-rose-100 text-rose-700 mr-1">DNC</span>
                                <span v-if="l.has_express_consent" class="pill bg-emerald-100 text-emerald-700">consent</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="lastPage > 1" class="flex items-center justify-between px-3 py-2 text-sm text-slate-600 border-t border-slate-200 bg-slate-50">
                    <span>Page {{ page }} of {{ lastPage }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost" :disabled="page <= 1" @click="page--">Prev</button>
                        <button class="btn-ghost" :disabled="page >= lastPage" @click="page++">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
