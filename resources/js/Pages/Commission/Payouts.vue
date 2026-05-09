<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Payout {
    id: string;
    user_id: string;
    period_start: string | null;
    period_end: string | null;
    total_earned: string;
    total_reversed: string;
    total_adjustments: string;
    net_payable: string;
    currency: string;
    status: string;
    paid_at: string | null;
    payment_reference: string | null;
    calculation_count: number;
    created_at: string | null;
}

const payouts = ref<Payout[]>([]);
const loading = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: Payout[] }>('/api/commission/payouts');
        payouts.value = data.data;
    } finally {
        loading.value = false;
    }
}

function statusClass(s: string): string {
    return {
        draft: 'bg-slate-100 text-slate-700',
        approved: 'bg-blue-100 text-blue-700',
        paid: 'bg-emerald-100 text-emerald-700',
        voided: 'bg-rose-100 text-rose-700',
    }[s] ?? 'bg-slate-100 text-slate-700';
}

onMounted(load);
</script>

<template>
    <AppLayout title="Commission Payouts">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Commission payouts</h2>
                    <p class="mt-1 text-sm text-slate-500">Period rollups: earned − reversed + adjustments = net payable.</p>
                </div>
                <button class="btn-ghost" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
            </div>

            <div class="panel overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Period</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">User</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Earned</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Reversed</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Adjust.</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Net</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Calcs</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!loading && payouts.length === 0">
                            <td colspan="8" class="px-3 py-12 text-center text-sm text-slate-500">
                                No payouts yet. Build one via <code>POST /api/commission/payouts/build</code> after a few payments clear.
                            </td>
                        </tr>
                        <tr v-for="p in payouts" :key="p.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-sm text-slate-900">{{ p.period_start }} → {{ p.period_end }}</td>
                            <td class="px-3 py-2 text-xs font-mono text-slate-600">{{ p.user_id.slice(0, 8) }}…</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-900">${{ p.total_earned }}</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums text-rose-700">−${{ p.total_reversed }}</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-700">${{ p.total_adjustments }}</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums font-semibold text-slate-900">${{ p.net_payable }}</td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ p.calculation_count }}</td>
                            <td class="px-3 py-2"><span class="pill" :class="statusClass(p.status)">{{ p.status }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
