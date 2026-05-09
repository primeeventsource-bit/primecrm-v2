<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

interface DncEntry {
    id: string;
    tenant_id: string | null;
    is_global: boolean;
    phone: string;
    source: string;
    reason: string | null;
    added_by: string | null;
    effective_date: string | null;
    expires_at: string | null;
    created_at: string;
}

const entries = ref<DncEntry[]>([]);
const loading = ref(false);
const filterSource = ref('');

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string> = {};
        if (filterSource.value) params.source = filterSource.value;
        const { data } = await axios.get<{ data: DncEntry[] }>('/api/compliance/dnc', { params });
        entries.value = data.data;
    } finally {
        loading.value = false;
    }
}

function sourceClass(s: string): string {
    return {
        federal_dnc: 'bg-rose-100 text-rose-700',
        state_dnc: 'bg-amber-100 text-amber-700',
        wireless_dnc: 'bg-orange-100 text-orange-700',
        litigator_dnc: 'bg-rose-200 text-rose-900',
        internal_dnc: 'bg-slate-100 text-slate-700',
        customer_request: 'bg-blue-100 text-blue-700',
    }[s] ?? 'bg-slate-100 text-slate-700';
}

onMounted(load);
</script>

<template>
    <AppLayout title="DNC List">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Do Not Call list</h2>
                    <p class="mt-1 text-sm text-slate-500">Tenant-scoped + global federal/state/wireless lists. The dialer's compliance guardrail checks this before every call.</p>
                </div>
                <div class="flex items-center gap-2">
                    <select v-model="filterSource" class="input" @change="load">
                        <option value="">All sources</option>
                        <option value="federal_dnc">Federal</option>
                        <option value="state_dnc">State</option>
                        <option value="wireless_dnc">Wireless</option>
                        <option value="litigator_dnc">Litigator</option>
                        <option value="internal_dnc">Internal</option>
                        <option value="customer_request">Customer request</option>
                    </select>
                </div>
            </div>

            <div class="panel overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Phone</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Source</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Scope</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Reason</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Added</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Expires</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!loading && entries.length === 0">
                            <td colspan="6" class="px-3 py-12 text-center text-sm text-slate-500">
                                No DNC entries yet. Run <code>php artisan compliance:dnc:import-federal</code> for federal DNC, or POST <code>/api/compliance/dnc</code> for tenant entries.
                            </td>
                        </tr>
                        <tr v-for="e in entries" :key="e.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-sm font-mono text-slate-900">{{ e.phone }}</td>
                            <td class="px-3 py-2"><span class="pill" :class="sourceClass(e.source)">{{ e.source.replace(/_/g, ' ') }}</span></td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ e.is_global ? 'global' : 'tenant' }}</td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ e.reason ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ e.created_at?.split('T')[0] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ e.expires_at ?? 'never' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
