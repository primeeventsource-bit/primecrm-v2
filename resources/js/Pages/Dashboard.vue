<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PageProps } from '@/types/api';

interface Summary {
    period: { kind: string; label: string; start: string; end: string; offset: number };
    pipeline: {
        total_transfers: number;
        deals_closed: number;
        deals_lost: number;
        deals_in_progress: number;
        sent_to_verification: number;
        charged: number;
        won_revenue: number;
        charged_amount: number;
        conversion_rate: number;
        charged_rate: number;
    };
    performance: {
        total_leads: number;
        transfer_rate: number;
        deals_closed: number;
        close_rate: number;
    };
    groups: Array<{
        group: string; role: string; location: string;
        agents: number; leads: number; deals: number;
        revenue: number; rate: number;
    }>;
}

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);

const period = ref<'daily' | 'weekly' | 'monthly'>('weekly');
const offset = ref(0);
const tab = ref<'overview' | 'closers' | 'fronters' | 'chart'>('overview');
const summary = ref<Summary | null>(null);
const loading = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<Summary>('/api/dashboard/summary', {
            params: { period: period.value, offset: offset.value },
        });
        summary.value = data;
    } finally {
        loading.value = false;
    }
}

watch([period, offset], () => void load());
onMounted(load);

function fmtMoney(n: number): string {
    return '$' + Math.round(n).toLocaleString('en-US');
}
function pct(n: number): string {
    return (n * 100).toFixed(1) + '%';
}

const filteredGroups = computed(() => {
    const all = summary.value?.groups ?? [];
    if (tab.value === 'closers') return all.filter((g) => g.role === 'closer');
    if (tab.value === 'fronters') return all.filter((g) => g.role === 'fronter');
    return all;
});

const dealsLabel = computed(() => {
    if (!summary.value) return '0 deals · $0 revenue';
    const p = summary.value.pipeline;
    return `${p.deals_closed} deal${p.deals_closed === 1 ? '' : 's'} · ${fmtMoney(p.won_revenue)} revenue`;
});
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="p-6 space-y-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900">Dashboard</h1>
                    <p class="text-sm text-slate-500">PRIME CRM · {{ user?.name }} · {{ user?.role.replace(/_/g, ' ') }}</p>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <label class="text-slate-500">View by:</label>
                    <select v-model="period" class="rounded-md border-slate-300 text-sm">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
            </div>

            <!-- Period banner -->
            <div class="panel flex items-center justify-between p-3">
                <div class="flex items-center gap-3">
                    <button class="text-slate-400 hover:text-slate-700 px-2" @click="offset++">‹</button>
                    <div>
                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 mr-2">
                            {{ offset === 0 ? '● Live' : '· Past' }}
                        </span>
                        <span class="font-medium text-slate-900">
                            {{ offset === 0 ? 'Current' : '' }}
                            {{ period === 'daily' ? 'Day' : period === 'monthly' ? 'Month' : 'Week' }}
                        </span>
                        <span class="ml-2 text-xs text-slate-500">{{ summary?.period.label ?? '' }}</span>
                        <div class="text-xs text-slate-500 mt-0.5">{{ dealsLabel }}</div>
                    </div>
                    <button class="text-slate-400 hover:text-slate-700 px-2" :disabled="offset <= 0" @click="offset = Math.max(0, offset - 1)">›</button>
                </div>
                <button class="btn-primary" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'Snapshot' }}</button>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-slate-200">
                <button v-for="t in (['overview','closers','fronters','chart'] as const)"
                    :key="t" class="px-4 py-2 text-sm capitalize border-b-2 -mb-px"
                    :class="tab === t
                        ? 'border-blue-600 text-blue-700 font-medium'
                        : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="tab = t">{{ t }}</button>
            </div>

            <!-- Pipeline summary -->
            <section v-if="tab !== 'chart'">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Company pipeline summary</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="panel p-4 border-t-4 border-blue-500">
                        <div class="text-xs uppercase text-slate-500">Total transfers</div>
                        <div class="mt-1 text-3xl font-bold text-blue-600">{{ summary?.pipeline.total_transfers ?? 0 }}</div>
                    </div>
                    <div class="panel p-4 border-t-4 border-purple-500">
                        <div class="text-xs uppercase text-slate-500">Deals closed</div>
                        <div class="mt-1 text-3xl font-bold text-purple-600">{{ summary?.pipeline.deals_closed ?? 0 }}</div>
                        <div class="text-xs text-slate-500">{{ pct(summary?.pipeline.conversion_rate ?? 0) }} conversion</div>
                    </div>
                    <div class="panel p-4 border-t-4 border-amber-500">
                        <div class="text-xs uppercase text-slate-500">Sent to verification</div>
                        <div class="mt-1 text-3xl font-bold text-amber-600">{{ summary?.pipeline.sent_to_verification ?? 0 }}</div>
                    </div>
                    <div class="panel p-4 border-t-4 border-emerald-500">
                        <div class="text-xs uppercase text-slate-500">Charged / Green</div>
                        <div class="mt-1 text-3xl font-bold text-emerald-600">{{ summary?.pipeline.charged ?? 0 }}</div>
                        <div class="text-xs text-slate-500">{{ pct(summary?.pipeline.charged_rate ?? 0) }} overall</div>
                    </div>
                </div>
            </section>

            <!-- Agent performance by location -->
            <section v-if="tab !== 'chart'">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-base font-semibold text-slate-900">Agent performance by location</h3>
                    <a href="/agents" class="text-sm text-blue-600 hover:underline">View Full Stats</a>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-3">
                    <div class="panel p-4">
                        <div class="text-xs uppercase text-slate-500">Total leads</div>
                        <div class="mt-1 text-3xl font-bold text-slate-900">{{ summary?.performance.total_leads ?? 0 }}</div>
                    </div>
                    <div class="panel p-4">
                        <div class="text-xs uppercase text-slate-500">Transfer rate</div>
                        <div class="mt-1 text-3xl font-bold text-blue-600">{{ pct(summary?.performance.transfer_rate ?? 0) }}</div>
                    </div>
                    <div class="panel p-4">
                        <div class="text-xs uppercase text-slate-500">Deals closed</div>
                        <div class="mt-1 text-3xl font-bold text-emerald-600">{{ summary?.performance.deals_closed ?? 0 }}</div>
                    </div>
                    <div class="panel p-4">
                        <div class="text-xs uppercase text-slate-500">Close rate</div>
                        <div class="mt-1 text-3xl font-bold text-emerald-600">{{ pct(summary?.performance.close_rate ?? 0) }}</div>
                    </div>
                </div>

                <div class="panel overflow-hidden">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Group</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Agents</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Leads</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Deals</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Revenue</th>
                                <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-if="filteredGroups.length === 0">
                                <td colspan="6" class="px-3 py-8 text-center text-slate-400">
                                    No agents yet. <a href="/agents" class="text-blue-600 hover:underline">Add some →</a>
                                </td>
                            </tr>
                            <tr v-for="g in filteredGroups" :key="g.group">
                                <td class="px-3 py-2">
                                    <span class="inline-block w-2 h-2 rounded-full mr-2" :class="g.role === 'fronter' ? 'bg-blue-500' : 'bg-emerald-500'"></span>
                                    {{ g.group }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ g.agents }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ g.leads }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ g.deals }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-700">{{ fmtMoney(g.revenue) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="g.rate > 0 ? 'text-emerald-700' : 'text-rose-600'">{{ pct(g.rate) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="tab === 'chart'" class="panel p-12 text-center text-slate-400">
                Chart view coming soon.
            </section>

            <!-- AI Performance Insights -->
            <section class="panel p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold text-slate-900">AI Performance Insights</div>
                        <div class="text-xs text-slate-500">Automated analysis of team and agent performance</div>
                    </div>
                    <a href="#" class="text-sm text-blue-600 hover:underline">View Details</a>
                </div>
                <div class="mt-3 text-sm text-slate-500 italic">
                    Insights generate automatically once you have at least 30 closed deals in the period.
                </div>
            </section>

            <!-- Automatic task list -->
            <section class="panel p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-base font-semibold text-slate-900">Automatic task list</div>
                        <div class="text-xs text-slate-500">All open tasks across all admins</div>
                    </div>
                    <div class="flex gap-1.5 text-xs">
                        <span class="pill bg-rose-100 text-rose-700">0 Overdue</span>
                        <span class="pill bg-amber-100 text-amber-700">0 Due Today</span>
                        <span class="pill bg-orange-100 text-orange-700">0 Urgent</span>
                        <span class="pill bg-blue-100 text-blue-700">0 Open</span>
                        <a href="#" class="text-blue-600 hover:underline ml-2">View all</a>
                    </div>
                </div>
                <div class="mt-6 text-center text-sm text-slate-400">No open tasks</div>
            </section>
        </div>
    </AppLayout>
</template>
