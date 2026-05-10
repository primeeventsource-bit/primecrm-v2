<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Listings hub — the post-sale operational view.
 *
 * Tabs map to status-filtered views; the active tab pulls from
 * /api/listings?tab=... with a single-trip query that also returns
 * tab-counts so badges stay in sync with whatever search/filter is
 * applied.
 */

type Tab =
    | 'all'
    | 'pending_distribution'
    | 'live'
    | 'with_inquiries'
    | 'booked'
    | 'expired_unrented';

interface PartnerPill {
    name: string;
    slug: string;
    status: string;
}

interface Row {
    id: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
    asking_price: number;
    owner_payout: number;
    went_live_at: string | null;
    expires_at: string | null;
    days_live: number | null;
    created_at: string | null;
    property: {
        id: string;
        resort_name: string;
        resort_brand: string | null;
        location_city: string;
        location_state: string;
    };
    owner: { id: string; name: string };
    deal_id: string;
    partner_summary: {
        sites_total: number;
        sites_live: number;
        sites_rejected: number;
        sites_paused: number;
        total_views: number;
        total_inquiries: number;
    };
    partner_pills: PartnerPill[];
}

interface Response {
    data: Row[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
    tab_counts: Record<Tab, number>;
    filters: { tab: Tab; q: string; sort: string; direction: string };
}

const tab = ref<Tab>('live');
const search = ref('');
const sort = ref<'check_in_date' | 'asking_price' | 'created_at' | 'went_live_at'>('check_in_date');
const direction = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const perPage = ref(25);

const rows = ref<Row[]>([]);
const meta = ref<Response['meta'] | null>(null);
const tabCounts = ref<Response['tab_counts'] | null>(null);
const loading = ref(false);

let searchTimer: number | undefined;

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<Response>('/api/listings', {
            params: {
                tab: tab.value,
                q: search.value,
                sort: sort.value,
                direction: direction.value,
                page: page.value,
                per_page: perPage.value,
            },
        });
        rows.value = data.data;
        meta.value = data.meta;
        tabCounts.value = data.tab_counts;
    } finally {
        loading.value = false;
    }
}

watch([tab, sort, direction, page, perPage], () => void load());
watch(search, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        page.value = 1;
        void load();
    }, 250);
});

onMounted(load);

const lastPage = computed(() => meta.value?.last_page ?? 1);

const tabs: Array<{ key: Tab; label: string }> = [
    { key: 'pending_distribution', label: 'Pending' },
    { key: 'live', label: 'Live' },
    { key: 'with_inquiries', label: 'Inquiries' },
    { key: 'booked', label: 'Booked' },
    { key: 'expired_unrented', label: 'Expired' },
    { key: 'all', label: 'All' },
];

function fmtMoney(n: number): string {
    if (!n) return '$0';
    if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(2)}M`;
    if (n >= 1000) return `$${(n / 1000).toFixed(1)}k`;
    return '$' + Math.round(n).toLocaleString('en-US');
}
function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return iso.split('T')[0];
}
function relabel(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ');
}
function statusColor(s: string): string {
    if (s === 'live' || s === 'inquiry_received' || s === 'pending_booking') return 'text-floor-win';
    if (s === 'booked' || s === 'rented_completed') return 'text-floor-info';
    if (s === 'unrented_expired' || s === 'cancelled') return 'text-floor-lose';
    return 'text-deck-soft';
}
function partnerDot(s: string): string {
    if (s === 'live') return 'bg-floor-win';
    if (s === 'pending') return 'bg-floor-accent';
    if (s === 'paused') return 'bg-amber-500';
    if (s === 'rejected' || s === 'removed') return 'bg-floor-lose';
    return 'bg-deck-dim';
}

function gotoListing(id: string): void {
    router.visit(`/listings/${id}`);
}
</script>

<template>
    <AppLayout title="Listings">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-deck-text">Listings</h1>
                    <p class="text-sm text-deck-soft">
                        Where your owners' weeks are right now — across every partner site.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="search owner or resort"
                        class="input text-sm w-64"
                    />
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-deck-line mb-4 overflow-x-auto">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    class="px-4 py-2 text-sm capitalize border-b-2 -mb-px transition-colors whitespace-nowrap"
                    :class="tab === t.key
                        ? 'border-floor-accent text-deck-text font-medium'
                        : 'border-transparent text-deck-dim hover:text-deck-soft'"
                    @click="(tab = t.key, page = 1)"
                >
                    <span>{{ t.label }}</span>
                    <span
                        v-if="tabCounts && tabCounts[t.key] !== undefined"
                        class="ml-2 inline-block px-1.5 py-0.5 rounded font-mono text-[10px] tabular-nums"
                        :class="tab === t.key ? 'bg-floor-accent/15 text-floor-accent' : 'bg-deck-muted text-deck-dim'"
                    >{{ tabCounts[t.key] }}</span>
                </button>
            </div>

            <!-- Sort + per-page controls -->
            <div class="flex items-center justify-between mb-3 text-xs text-deck-soft">
                <div>
                    <span v-if="meta">{{ meta.total }} listing{{ meta.total === 1 ? '' : 's' }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <label>Sort</label>
                    <select v-model="sort" class="input text-xs py-1">
                        <option value="check_in_date">Check-in date</option>
                        <option value="asking_price">Asking price</option>
                        <option value="created_at">Created</option>
                        <option value="went_live_at">Live since</option>
                    </select>
                    <select v-model="direction" class="input text-xs py-1">
                        <option value="asc">↑ asc</option>
                        <option value="desc">↓ desc</option>
                    </select>
                </div>
            </div>

            <!-- Results table -->
            <div class="panel overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-deck-line">
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Resort / Owner</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Dates</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Asking</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Owner gets</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Sites</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Days live</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Views</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Inquiries</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-deck-line/50">
                        <tr v-if="!loading && rows.length === 0">
                            <td colspan="9" class="px-3 py-12 text-center text-sm text-deck-dim italic">
                                <template v-if="tab === 'pending_distribution'">No listings waiting on distribution. Quiet means everyone's live.</template>
                                <template v-else-if="tab === 'live'">No live listings yet — they show up here once a fee clears and the property is verified.</template>
                                <template v-else-if="tab === 'with_inquiries'">No active inquiries. Renters reach out via partner sites; their messages land here.</template>
                                <template v-else-if="tab === 'booked'">No bookings yet. The success metric of the listing service goes here.</template>
                                <template v-else-if="tab === 'expired_unrented'">Nothing expired unrented — that's the goal.</template>
                                <template v-else>No listings on file.</template>
                            </td>
                        </tr>
                        <tr
                            v-for="r in rows"
                            :key="r.id"
                            class="cursor-pointer hover:bg-deck-raised/40"
                            @click="gotoListing(r.id)"
                        >
                            <!-- Resort / Owner -->
                            <td class="px-3 py-2">
                                <div class="text-sm text-deck-text">{{ r.property.resort_name }}</div>
                                <div class="text-xs text-deck-dim">
                                    <span v-if="r.property.resort_brand">{{ r.property.resort_brand }} · </span>
                                    {{ r.property.location_city }}, {{ r.property.location_state }}
                                </div>
                                <div class="text-[11px] text-deck-soft mt-0.5" @click.stop>
                                    Owner: <Link :href="`/owners/${r.owner.id}`" class="text-floor-accent hover:underline">{{ r.owner.name }}</Link>
                                </div>
                            </td>
                            <!-- Dates -->
                            <td class="px-3 py-2 font-mono tabular-nums text-xs text-deck-soft whitespace-nowrap">
                                {{ fmtDate(r.check_in_date) }}<br>
                                <span class="text-deck-dim">→ {{ fmtDate(r.check_out_date) }}</span>
                            </td>
                            <!-- Asking -->
                            <td class="px-3 py-2 text-right deck-num">{{ fmtMoney(r.asking_price) }}</td>
                            <!-- Owner gets -->
                            <td class="px-3 py-2 text-right deck-num text-floor-win">{{ fmtMoney(r.owner_payout) }}</td>
                            <!-- Status -->
                            <td class="px-3 py-2 font-mono text-xs uppercase tracking-wider" :class="statusColor(r.status)">
                                {{ relabel(r.status) }}
                            </td>
                            <!-- Sites pills -->
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1 max-w-[180px]">
                                    <span
                                        v-for="p in r.partner_pills"
                                        :key="p.slug"
                                        class="inline-flex items-center gap-1 rounded border border-deck-line bg-deck-bg px-1.5 py-0.5 text-[9px] font-mono uppercase tracking-wider text-deck-soft"
                                        :title="`${p.name} · ${p.status}`"
                                    >
                                        <span class="inline-block h-1.5 w-1.5 rounded-full" :class="partnerDot(p.status)"></span>
                                        {{ p.slug }}
                                    </span>
                                </div>
                                <div v-if="r.partner_summary.sites_rejected > 0" class="mt-1 text-[10px] text-floor-lose">
                                    {{ r.partner_summary.sites_rejected }} rejected
                                </div>
                            </td>
                            <!-- Days live -->
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-xs"
                                :class="r.days_live === null
                                    ? 'text-deck-dim'
                                    : r.days_live > 30 ? 'text-floor-lose' : r.days_live > 7 ? 'text-floor-accent' : 'text-deck-text'">
                                {{ r.days_live === null ? '—' : `${r.days_live}d` }}
                            </td>
                            <!-- Views -->
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-xs text-deck-soft">
                                {{ r.partner_summary.total_views || '—' }}
                            </td>
                            <!-- Inquiries -->
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-xs"
                                :class="r.partner_summary.total_inquiries > 0 ? 'text-floor-accent' : 'text-deck-dim'">
                                {{ r.partner_summary.total_inquiries || '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="lastPage > 1" class="flex items-center justify-between px-3 py-2 text-sm text-deck-soft border-t border-deck-line">
                    <span>Page {{ page }} of {{ lastPage }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost text-xs" :disabled="page <= 1" @click="page--">Prev</button>
                        <button class="btn-ghost text-xs" :disabled="page >= lastPage" @click="page++">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
