<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import FloorTicker, { type TickerEvent } from '@/Components/Floor/Ticker.vue';
import FloorStatusPill from '@/Components/Floor/StatusPill.vue';
import type { PageProps } from '@/types/api';

/**
 * Dashboard / Floor OS command deck.
 *
 *   – Hero KPI: Total Transfers (the leading indicator that drives
 *     everything downstream). Big mono number, sparkline-ready.
 *   – Secondary trio: Closed / Verify / Green — same data the legacy
 *     dashboard had, but visually subordinate to the hero.
 *   – Floor leaderboard: live agent list with status pills + rank.
 *   – Activity ticker: glance-and-know event stream.
 *
 * Empty states intentionally avoid a wall of zeros. Em-dashes + a
 * short voiced sentence beat a misleading "0".
 */

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

// Auto-refresh every 30s while live (offset=0). Past windows are
// snapshots; no point churning their numbers. The umbrella loader
// is defined further down once activity / sparkline / leaderboard
// fetchers exist.
let refreshTimer: number | undefined;
function setupAutoRefresh(): void {
    if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
    if (offset.value === 0) {
        refreshTimer = window.setInterval(() => void loadAll(), 30_000);
    }
}
watch(offset, setupAutoRefresh, { immediate: true });
onUnmounted(() => {
    if (refreshTimer !== undefined) window.clearInterval(refreshTimer);
});

function fmtMoney(n: number | null | undefined): string {
    if (n == null || !n) return '$0';
    if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(2)}M`;
    if (n >= 1000) return `$${(n / 1000).toFixed(1)}k`;
    return '$' + Math.round(n).toLocaleString('en-US');
}
function pct(n: number | null | undefined): string {
    if (n == null) return '—';
    return (n * 100).toFixed(1) + '%';
}
function num(n: number | null | undefined): string {
    if (n == null || n === 0) return '—';
    return n.toLocaleString('en-US');
}

const filteredGroups = computed(() => {
    const all = summary.value?.groups ?? [];
    if (tab.value === 'closers') return all.filter((g) => g.role === 'closer');
    if (tab.value === 'fronters') return all.filter((g) => g.role === 'fronter');
    return all;
});

const heroLabel = computed(() => {
    if (!summary.value) return 'no data yet';
    const p = summary.value.pipeline;
    if (p.deals_closed === 0 && p.total_transfers === 0) return 'no activity yet this period';
    return `${p.deals_closed} deal${p.deals_closed === 1 ? '' : 's'} · ${fmtMoney(p.won_revenue)} won`;
});

const periodWord = computed(() => {
    if (period.value === 'daily') return 'Day';
    if (period.value === 'monthly') return 'Month';
    return 'Week';
});

// Floor leaderboard — fed from /api/dashboard/floor-status. Sorted
// by today's revenue server-side; we just render the order we get.
interface LeaderRow {
    rank: number;
    id: string;
    name: string;
    role: 'closer' | 'fronter';
    location: 'US' | 'Panama';
    deals_today: number;
    revenue_today: number;
    status: 'on_call' | 'idle' | 'wrap' | 'offline' | 'available' | 'wrap_up' | 'on_break';
    since: string | null;
}
const leaderboard = ref<LeaderRow[]>([]);

// Activity ticker — pulled from /api/dashboard/activity; latest 30
// events from the last hour. Refreshed in lockstep with the summary.
const tickerEvents = ref<TickerEvent[]>([]);

// Hero KPI sparkline — bucketed transfer counts. Bars scale to the
// max value in the series so quiet hours don't ghost the busy ones.
interface SparkPoint { t: string; v: number }
const sparkSeries = ref<SparkPoint[]>([]);
const sparkMax = computed(() => Math.max(1, ...sparkSeries.value.map((p) => p.v)));

// Map FloorStatusPill's tighter status vocabulary onto the broader
// agent_statuses set we get from the API.
function leaderStatus(s: LeaderRow['status']): 'on_call' | 'idle' | 'wrap' | 'offline' {
    if (s === 'on_call') return 'on_call';
    if (s === 'wrap_up' || s === 'wrap') return 'wrap';
    if (s === 'offline') return 'offline';
    return 'idle';
}

async function loadActivity(): Promise<void> {
    try {
        const { data } = await axios.get<{ data: TickerEvent[] }>('/api/dashboard/activity', {
            params: { limit: 30, since_minutes: 240 },
        });
        tickerEvents.value = data.data;
    } catch {
        tickerEvents.value = [];
    }
}

async function loadSparkline(): Promise<void> {
    try {
        const { data } = await axios.get<{ series: SparkPoint[] }>('/api/dashboard/sparkline', {
            params: { metric: 'transfers', period: 'daily', buckets: 24 },
        });
        sparkSeries.value = data.series;
    } catch {
        sparkSeries.value = [];
    }
}

async function loadLeaderboard(): Promise<void> {
    try {
        const { data } = await axios.get<{ data: LeaderRow[] }>('/api/dashboard/floor-status');
        leaderboard.value = data.data;
    } catch {
        leaderboard.value = [];
    }
}

// One umbrella loader the auto-refresh re-uses so the ticker, the
// leaderboard, and the sparkline all advance in lockstep with the
// summary cards rather than flashing piecemeal.
async function loadAll(): Promise<void> {
    await Promise.all([
        load(), loadActivity(), loadSparkline(), loadLeaderboard(),
        loadListingHealth(), loadPartnerHealth(), loadBookingPipeline(),
        loadCompliancePosture(), loadOwnerSignals(),
    ]);
}

/* ------------------------------------------------------------------
 | Listing-service health (D7)
 |
 | Five parallel fetches feed the second half of the dashboard. They're
 | refreshed in lockstep with the call-floor summary (loadAll picks
 | them up). Empty state on any of them just hides the panel — we'd
 | rather a missing panel than a broken page.
 |------------------------------------------------------------------ */

interface ListingHealth {
    listings_live: { count: number; inventory_value: number };
    bookings_this_week: { count: number; rental_value: number; commission: number; owners_notified: number };
    time_to_live: { median_seconds: number | null; p90_seconds: number | null; sample_size: number };
    reversal_rate: { rate: number | null; refund_cases: number; chargeback_cases: number; cleared_payments: number };
    going_dark_soon: Array<{ id: string; resort_name: string; state: string; check_in_date: string; asking_price: number; days_until: number; owner_id: string; owner_name: string }>;
    by_state: Array<{ state: string; count: number; value: number }>;
}
interface PartnerHealthRow {
    id: string;
    name: string;
    slug: string;
    is_active: boolean;
    pushes_total: number;
    pushes_live: number;
    pushes_rejected: number;
    avg_ttl_seconds: number | null;
    views: number;
    inquiries: number;
    bookings: number;
    conversion_rate: number | null;
}
interface FunnelCounts {
    inquiries: number;
    negotiating: number;
    booked: number;
    completed: number;
    lost: number;
}
interface BookingPipeline {
    this_week: FunnelCounts;
    last_week: FunnelCounts;
    stale_inquiries: number;
    avg_days_to_book: number | null;
    avg_days_sample: number;
}
interface CloserRefundRow {
    id: string;
    name: string;
    closes: number;
    refund_cases: number;
    refund_rate: number;
}
interface CompliancePosture {
    disclosure_pass_rate: number | null;
    disclosure_target: number;
    disclosure_breakdown: { total: number; passed: number; failed: number; flagged: number; pending: number };
    open_refunds_by_reason: Array<{ reason: string; count: number; amount: number }>;
    open_chargebacks: Array<{ id: string; processor_case_id: string; amount: number; respond_by_date: string; status: string; days_until_due: number | null }>;
    closer_refund_rates: CloserRefundRow[];
}
interface OwnerSignals {
    relist_rate: number | null;
    multi_deal_owners: number;
    total_owners_with_deals: number;
    avg_lifetime_fees: number | null;
    upsell_opportunities: Array<{ owner_id: string; owner_name: string; properties: number; active_listings: number; untapped: number }>;
}

const listingHealth = ref<ListingHealth | null>(null);
const partnerHealth = ref<PartnerHealthRow[]>([]);
const bookingPipeline = ref<BookingPipeline | null>(null);
const compliancePosture = ref<CompliancePosture | null>(null);
const ownerSignals = ref<OwnerSignals | null>(null);

async function loadListingHealth(): Promise<void> {
    try {
        const { data } = await axios.get<ListingHealth>('/api/dashboard/listing-health');
        listingHealth.value = data;
    } catch { listingHealth.value = null; }
}
async function loadPartnerHealth(): Promise<void> {
    try {
        const { data } = await axios.get<{ data: PartnerHealthRow[] }>('/api/dashboard/partner-health');
        partnerHealth.value = data.data;
    } catch { partnerHealth.value = []; }
}
async function loadBookingPipeline(): Promise<void> {
    try {
        const { data } = await axios.get<BookingPipeline>('/api/dashboard/booking-pipeline');
        bookingPipeline.value = data;
    } catch { bookingPipeline.value = null; }
}
async function loadCompliancePosture(): Promise<void> {
    try {
        const { data } = await axios.get<CompliancePosture>('/api/dashboard/compliance-posture');
        compliancePosture.value = data;
    } catch { compliancePosture.value = null; }
}
async function loadOwnerSignals(): Promise<void> {
    try {
        const { data } = await axios.get<OwnerSignals>('/api/dashboard/owner-signals');
        ownerSignals.value = data;
    } catch { ownerSignals.value = null; }
}

/* ------------------------------------------------------------------
 | Domain-aware formatters
 |------------------------------------------------------------------ */

function fmtDuration(seconds: number | null): string {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    if (seconds < 86400) return `${(seconds / 3600).toFixed(1)}h`;
    return `${(seconds / 86400).toFixed(1)}d`;
}

function ttlColor(seconds: number | null): string {
    if (seconds === null) return 'text-deck-dim';
    if (seconds < 48 * 3600) return 'text-floor-win';
    if (seconds < 5 * 86400) return 'text-floor-accent';
    return 'text-floor-lose';
}

function reversalColor(rate: number | null): string {
    if (rate === null) return 'text-deck-dim';
    if (rate < 0.01) return 'text-floor-win';
    if (rate < 0.02) return 'text-floor-accent';
    return 'text-floor-lose';
}

function chargebackUrgency(days: number | null): string {
    if (days === null) return 'text-deck-soft';
    if (days < 0) return 'text-floor-lose font-bold';
    if (days <= 3) return 'text-floor-lose';
    if (days <= 7) return 'text-floor-accent';
    return 'text-deck-soft';
}

onMounted(() => {
    void loadActivity();
    void loadSparkline();
    void loadLeaderboard();
    void loadListingHealth();
    void loadPartnerHealth();
    void loadBookingPipeline();
    void loadCompliancePosture();
    void loadOwnerSignals();
});
</script>

<template>
    <AppLayout title="Floor OS">
        <div class="p-6 space-y-5">
            <!-- ──────────────────────────────────────────────────
                 Header strip — period nav + identity
                 ────────────────────────────────────────────────── -->
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="deck-dot-live"></span>
                        <span class="deck-label">Command Deck</span>
                    </div>
                    <h1 class="mt-1 text-2xl font-semibold text-deck-text tracking-tight">
                        Welcome back, {{ user?.name?.split(' ')[0] ?? 'agent' }}.
                    </h1>
                    <p class="text-sm text-deck-soft">
                        {{ user?.role.replace(/_/g, ' ') }} · auto-refreshes every 30s while live
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <div class="inline-flex rounded-md border border-deck-line bg-deck-surface p-0.5">
                        <button
                            v-for="p in (['daily','weekly','monthly'] as const)"
                            :key="p"
                            class="px-3 py-1 text-xs font-mono uppercase tracking-wider rounded"
                            :class="period === p
                                ? 'bg-deck-raised text-deck-text'
                                : 'text-deck-dim hover:text-deck-soft'"
                            @click="period = p"
                        >{{ p }}</button>
                    </div>
                </div>
            </div>

            <!-- Period banner — Live / Past with offset nav -->
            <div class="deck-card flex items-center justify-between p-3">
                <div class="flex items-center gap-3">
                    <button
                        class="text-deck-dim hover:text-deck-text px-2 transition-colors"
                        title="Previous period"
                        @click="offset++"
                    >‹</button>
                    <div>
                        <div class="flex items-center gap-2">
                            <span v-if="offset === 0" class="pill bg-floor-win/15 text-floor-win ring-1 ring-floor-win/30">
                                <span class="deck-dot bg-floor-win mr-1 animate-pulse-dot"></span> LIVE
                            </span>
                            <span v-else class="pill bg-deck-muted text-deck-soft ring-1 ring-deck-line">
                                · PAST
                            </span>
                            <span class="text-sm font-medium text-deck-text">
                                {{ offset === 0 ? 'Current' : '' }} {{ periodWord }}
                            </span>
                            <span class="font-mono text-xs text-deck-dim">{{ summary?.period.label ?? '' }}</span>
                        </div>
                        <div class="text-xs text-deck-soft mt-0.5">{{ heroLabel }}</div>
                    </div>
                    <button
                        class="text-deck-dim hover:text-deck-text px-2 transition-colors disabled:opacity-30"
                        :disabled="offset <= 0"
                        title="More recent period"
                        @click="offset = Math.max(0, offset - 1)"
                    >›</button>
                </div>
                <button class="btn-primary" :disabled="loading" @click="loadAll">
                    <span v-if="loading">Loading…</span>
                    <span v-else>↻ Snapshot</span>
                </button>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-deck-line">
                <button v-for="t in (['overview','closers','fronters','chart'] as const)"
                    :key="t"
                    class="px-4 py-2 text-sm capitalize border-b-2 -mb-px transition-colors"
                    :class="tab === t
                        ? 'border-floor-accent text-deck-text font-medium'
                        : 'border-transparent text-deck-dim hover:text-deck-soft'"
                    @click="tab = t">{{ t }}</button>
            </div>

            <!-- ──────────────────────────────────────────────────
                 Hero KPI grid — Total Transfers leads, others
                 visually subordinate.
                 ────────────────────────────────────────────────── -->
            <section v-if="tab !== 'chart'" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Hero card -->
                <div class="deck-card p-5 lg:col-span-1 relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-br from-floor-accent/10 via-transparent to-transparent pointer-events-none"></div>
                    <div class="relative">
                        <div class="deck-label text-floor-accent">Total Transfers</div>
                        <div class="mt-3 deck-num-hero">
                            <span v-if="summary?.pipeline.total_transfers">{{ summary.pipeline.total_transfers.toLocaleString('en-US') }}</span>
                            <span v-else class="text-deck-dim">—</span>
                        </div>
                        <div class="mt-2 text-xs text-deck-soft">
                            <span v-if="summary && summary.pipeline.total_transfers > 0">
                                Leading indicator · drives everything downstream
                            </span>
                            <span v-else class="italic">
                                No transfers yet this {{ periodWord.toLowerCase() }}. The hot number lives here.
                            </span>
                        </div>
                        <!-- Sparkline — bucketed transfers from /api/dashboard/sparkline.
                             Bars scale to series max so the busiest hour pegs at 100%.
                             Falls back to 24 ghost bars while data is in flight. -->
                        <div class="mt-4 h-10 flex items-end gap-1">
                            <template v-if="sparkSeries.length > 0">
                                <div
                                    v-for="p in sparkSeries"
                                    :key="p.t"
                                    :title="`${new Date(p.t).toLocaleTimeString('en-US', { hour: 'numeric' })} · ${p.v} transfer${p.v === 1 ? '' : 's'}`"
                                    class="flex-1 rounded-sm transition-all"
                                    :class="p.v > 0 ? 'bg-floor-accent/60' : 'bg-floor-accent/10'"
                                    :style="{ height: `${Math.max(8, (p.v / sparkMax) * 100)}%` }"
                                ></div>
                            </template>
                            <template v-else>
                                <div
                                    v-for="i in 24"
                                    :key="`ph-${i}`"
                                    class="flex-1 rounded-sm bg-floor-accent/10"
                                    style="height: 8%"
                                ></div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Secondary trio -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:col-span-2">
                    <div class="deck-card p-4">
                        <div class="deck-label">Listing fees</div>
                        <div class="mt-2 deck-num-stat text-floor-win">{{ num(summary?.pipeline.deals_closed) }}</div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            {{ pct(summary?.pipeline.conversion_rate) }} pitch-to-listing
                        </div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Sent to verify</div>
                        <div class="mt-2 deck-num-stat text-floor-accent">{{ num(summary?.pipeline.sent_to_verification) }}</div>
                        <div class="mt-1 text-xs text-deck-dim">awaiting verification team</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Charged · Green</div>
                        <div class="mt-2 deck-num-stat text-floor-win">{{ num(summary?.pipeline.charged) }}</div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            {{ fmtMoney(summary?.pipeline.charged_amount ?? 0) }} cleared
                        </div>
                    </div>
                </div>
            </section>

            <!-- ──────────────────────────────────────────────────
                 Performance + leaderboard + ticker
                 ────────────────────────────────────────────────── -->
            <section v-if="tab !== 'chart'" class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <!-- Left two-thirds: performance metrics + group breakdown -->
                <div class="xl:col-span-2 space-y-4">
                    <!-- Performance metric strip -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="deck-card p-3">
                            <div class="deck-label">Total leads</div>
                            <div class="mt-1 deck-num text-xl">{{ num(summary?.performance.total_leads) }}</div>
                        </div>
                        <div class="deck-card p-3">
                            <div class="deck-label">Transfer rate</div>
                            <div class="mt-1 deck-num text-xl text-floor-info">{{ pct(summary?.performance.transfer_rate) }}</div>
                        </div>
                        <div class="deck-card p-3">
                            <div class="deck-label">Closed</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ num(summary?.performance.deals_closed) }}</div>
                        </div>
                        <div class="deck-card p-3">
                            <div class="deck-label">Close rate</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ pct(summary?.performance.close_rate) }}</div>
                        </div>
                    </div>

                    <!-- Group breakdown -->
                    <div class="deck-card overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-deck-line">
                            <div>
                                <div class="text-sm font-semibold text-deck-text">Performance by location</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">Fronter / Closer · US / Panama</div>
                            </div>
                            <a href="/agents" class="text-xs text-floor-accent hover:text-floor-accentHi">View full stats →</a>
                        </div>
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-deck-line">
                                    <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Group</th>
                                    <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Agents</th>
                                    <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Leads</th>
                                    <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Deals</th>
                                    <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Revenue</th>
                                    <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-deck-line/50">
                                <tr v-if="filteredGroups.length === 0">
                                    <td colspan="6" class="px-4 py-10 text-center text-sm text-deck-dim italic">
                                        No agents on the floor yet —
                                        <a href="/agents" class="text-floor-accent hover:text-floor-accentHi">add a few</a>
                                        and they'll show up here.
                                    </td>
                                </tr>
                                <tr v-for="g in filteredGroups" :key="g.group" class="hover:bg-deck-raised/40">
                                    <td class="px-4 py-2">
                                        <span class="inline-block w-1.5 h-1.5 rounded-full mr-2"
                                              :class="g.role === 'fronter' ? 'bg-floor-info' : 'bg-floor-win'"></span>
                                        <span class="text-deck-text">{{ g.group }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right deck-num">{{ g.agents }}</td>
                                    <td class="px-4 py-2 text-right deck-num">{{ g.leads }}</td>
                                    <td class="px-4 py-2 text-right deck-num">{{ g.deals }}</td>
                                    <td class="px-4 py-2 text-right deck-num text-floor-win">{{ fmtMoney(g.revenue) }}</td>
                                    <td class="px-4 py-2 text-right deck-num"
                                        :class="g.rate > 0 ? 'text-floor-win' : 'text-deck-dim'">
                                        {{ pct(g.rate) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right third: floor leaderboard + ticker -->
                <div class="xl:col-span-1 space-y-4">
                    <!-- Leaderboard -->
                    <div class="deck-card overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-deck-line">
                            <div class="flex items-center gap-2">
                                <span class="deck-dot-live"></span>
                                <span class="text-sm font-semibold text-deck-text">Floor leaderboard</span>
                            </div>
                            <span class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">live</span>
                        </div>
                        <ul v-if="leaderboard.length > 0" class="divide-y divide-deck-line/50">
                            <li v-for="row in leaderboard" :key="row.id"
                                class="flex items-center gap-3 px-4 py-2.5 hover:bg-deck-raised/40">
                                <span class="font-mono tabular-nums text-deck-dim text-sm w-5 text-right">{{ row.rank }}</span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm text-deck-text truncate">{{ row.name }}</div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        {{ row.role }} · {{ row.location }} · {{ row.deals_today }} today
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-mono tabular-nums" :class="row.revenue_today > 0 ? 'text-floor-win' : 'text-deck-dim'">{{ fmtMoney(row.revenue_today) }}</div>
                                    <FloorStatusPill :status="leaderStatus(row.status)" :since="row.since" class="mt-0.5" />
                                </div>
                            </li>
                        </ul>
                        <div v-else class="px-4 py-8 text-center text-sm text-deck-dim italic">
                            Leaderboard wakes up when the floor does. No agents on shift yet.
                        </div>
                    </div>

                    <!-- Activity ticker -->
                    <FloorTicker
                        :events="tickerEvents"
                        empty-hint="Quiet on the floor. New transfers, closes, and charges will land here as they happen."
                    />
                </div>
            </section>

            <!-- Chart placeholder -->
            <section v-if="tab === 'chart'" class="deck-card p-12 text-center">
                <div class="deck-label">Chart view</div>
                <p class="mt-2 text-sm text-deck-soft italic">
                    Time-series view is on deck. Until then, the period nav above is your toolbar.
                </p>
            </section>

            <!-- ════════════════════════════════════════════════════════════════
                 LISTING SERVICE HEALTH (D7)
                 The post-sale side of the business. Above = call floor;
                 below = is the service we're selling actually delivering?
                 ════════════════════════════════════════════════════════════════ -->
            <section v-if="tab !== 'chart'" class="pt-2">
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-block h-2 w-2 rounded-sm bg-floor-info"></span>
                    <h2 class="text-base font-semibold text-deck-text">Listing service health</h2>
                    <span class="text-[10px] font-mono uppercase tracking-[0.18em] text-deck-dim">
                        post-sale delivery
                    </span>
                </div>

                <!-- Row 2 hero KPIs — §3.1 of the prompt -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <!-- Listings live -->
                    <div class="deck-card p-4">
                        <div class="deck-label">Listings live</div>
                        <div class="mt-2 deck-num-stat text-floor-win">
                            {{ listingHealth?.listings_live.count ?? '—' }}
                        </div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            {{ listingHealth ? fmtMoney(listingHealth.listings_live.inventory_value) : '—' }} inventory
                        </div>
                    </div>
                    <!-- Bookings this week -->
                    <div class="deck-card p-4">
                        <div class="deck-label">Bookings this week</div>
                        <div class="mt-2 deck-num-stat text-floor-win">
                            {{ listingHealth?.bookings_this_week.count ?? '—' }}
                        </div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            {{ listingHealth ? fmtMoney(listingHealth.bookings_this_week.commission) : '—' }} earned
                        </div>
                    </div>
                    <!-- Time to live -->
                    <div class="deck-card p-4">
                        <div class="deck-label">Time to live (median)</div>
                        <div class="mt-2 deck-num-stat" :class="ttlColor(listingHealth?.time_to_live.median_seconds ?? null)">
                            {{ fmtDuration(listingHealth?.time_to_live.median_seconds ?? null) }}
                        </div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            p90 {{ fmtDuration(listingHealth?.time_to_live.p90_seconds ?? null) }}
                            <span v-if="(listingHealth?.time_to_live.sample_size ?? 0) > 0">
                                · n={{ listingHealth?.time_to_live.sample_size }}
                            </span>
                        </div>
                    </div>
                    <!-- Refund / chargeback rate -->
                    <div class="deck-card p-4">
                        <div class="deck-label">Refund / chargeback rate</div>
                        <div class="mt-2 deck-num-stat" :class="reversalColor(listingHealth?.reversal_rate.rate ?? null)">
                            {{ listingHealth?.reversal_rate.rate !== null && listingHealth?.reversal_rate.rate !== undefined ? pct(listingHealth.reversal_rate.rate) : '—' }}
                        </div>
                        <div class="mt-1 text-xs font-mono tabular-nums text-deck-dim">
                            <span v-if="listingHealth">
                                {{ listingHealth.reversal_rate.refund_cases }}R · {{ listingHealth.reversal_rate.chargeback_cases }}C / {{ listingHealth.reversal_rate.cleared_payments }} cleared
                            </span>
                            <span v-else>—</span>
                            <span class="block text-[10px] text-deck-dim">target &lt; 1% · alert &gt; 2%</span>
                        </div>
                    </div>
                </div>

                <!-- Two-column row: Going-dark-soon + Booking pipeline funnel -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mb-4">
                    <!-- Going dark soon — listings within 14 days, no booking -->
                    <div class="deck-card overflow-hidden">
                        <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-deck-text">Going dark soon</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">≤14 days · no booking</div>
                            </div>
                            <span v-if="listingHealth?.going_dark_soon.length" class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30 font-mono">
                                {{ listingHealth.going_dark_soon.length }}
                            </span>
                        </header>
                        <div v-if="!listingHealth?.going_dark_soon.length" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                            Nothing about to expire unrented. Clean board.
                        </div>
                        <ul v-else class="divide-y divide-deck-line/50">
                            <li v-for="l in listingHealth.going_dark_soon" :key="l.id"
                                class="px-4 py-2 flex items-center justify-between hover:bg-deck-raised/40">
                                <div class="min-w-0 flex-1">
                                    <Link :href="`/listings/${l.id}`" class="text-sm text-deck-text hover:text-floor-accent truncate block">
                                        {{ l.resort_name }}
                                    </Link>
                                    <div class="text-[11px] text-deck-soft">
                                        {{ l.owner_name }} · {{ l.state }}
                                    </div>
                                </div>
                                <div class="text-right ml-3">
                                    <div class="font-mono text-sm" :class="l.days_until <= 3 ? 'text-floor-lose' : 'text-floor-accent'">
                                        {{ l.days_until }}d
                                    </div>
                                    <div class="font-mono text-[10px] text-deck-dim">{{ fmtMoney(l.asking_price) }}</div>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Booking pipeline funnel — 2 columns wide -->
                    <div class="deck-card overflow-hidden xl:col-span-2">
                        <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-deck-text">Booking pipeline</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    this week · last week comparison
                                </div>
                            </div>
                            <div v-if="bookingPipeline" class="text-right text-xs">
                                <span v-if="bookingPipeline.stale_inquiries > 0" class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30 font-mono">
                                    {{ bookingPipeline.stale_inquiries }} stale (&gt;4h)
                                </span>
                                <span v-if="bookingPipeline.avg_days_to_book !== null" class="ml-2 text-deck-soft">
                                    avg {{ bookingPipeline.avg_days_to_book }}d to book
                                </span>
                            </div>
                        </header>
                        <div v-if="!bookingPipeline" class="px-4 py-8 text-center text-sm text-deck-dim italic">Loading…</div>
                        <div v-else class="p-4">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div v-for="stage in (['inquiries','negotiating','booked','completed'] as const)" :key="stage" class="text-center">
                                    <div class="deck-label">{{ stage }}</div>
                                    <div class="mt-1 deck-num text-2xl"
                                         :class="stage === 'completed' ? 'text-floor-win' : stage === 'booked' ? 'text-floor-info' : 'text-deck-text'">
                                        {{ bookingPipeline.this_week[stage] }}
                                    </div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mt-0.5">
                                        last wk {{ bookingPipeline.last_week[stage] }}
                                        <span v-if="bookingPipeline.this_week[stage] > bookingPipeline.last_week[stage]" class="text-floor-win">▲</span>
                                        <span v-else-if="bookingPipeline.this_week[stage] < bookingPipeline.last_week[stage]" class="text-floor-lose">▼</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="bookingPipeline.this_week.lost > 0" class="mt-3 pt-3 border-t border-deck-line/50 text-xs text-deck-dim">
                                <span class="font-mono">{{ bookingPipeline.this_week.lost }}</span> lost this week
                                <span class="ml-2">(last wk {{ bookingPipeline.last_week.lost }})</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Partner site health table -->
                <div class="deck-card overflow-hidden mb-4">
                    <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                        <div>
                            <div class="text-sm font-semibold text-deck-text">Partner site health</div>
                            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                where listings are getting traction
                            </div>
                        </div>
                        <Link href="/partner-sites" class="text-xs text-floor-accent hover:underline">Config →</Link>
                    </header>
                    <table v-if="partnerHealth.length" class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-deck-line">
                                <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Site</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Pushes</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Live</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Avg TTL</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Views</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Inquiries</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Bookings</th>
                                <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Conv.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-deck-line/50">
                            <tr v-for="p in partnerHealth" :key="p.id" class="hover:bg-deck-raised/40">
                                <td class="px-4 py-2">
                                    <span class="inline-block h-2 w-2 rounded-full mr-2"
                                          :class="p.is_active && p.pushes_live > 0 ? 'bg-floor-win'
                                              : p.pushes_rejected > 0 ? 'bg-floor-lose' : 'bg-deck-dim'"></span>
                                    <span class="text-deck-text">{{ p.name }}</span>
                                    <span v-if="!p.is_active" class="ml-2 text-[10px] font-mono uppercase tracking-wider text-deck-dim">inactive</span>
                                </td>
                                <td class="px-4 py-2 text-right deck-num">{{ p.pushes_total || '—' }}</td>
                                <td class="px-4 py-2 text-right deck-num text-floor-win">{{ p.pushes_live || '—' }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-xs"
                                    :class="ttlColor(p.avg_ttl_seconds)">
                                    {{ fmtDuration(p.avg_ttl_seconds) }}
                                </td>
                                <td class="px-4 py-2 text-right deck-num">{{ p.views || '—' }}</td>
                                <td class="px-4 py-2 text-right deck-num text-floor-accent">{{ p.inquiries || '—' }}</td>
                                <td class="px-4 py-2 text-right deck-num text-floor-info">{{ p.bookings || '—' }}</td>
                                <td class="px-4 py-2 text-right font-mono tabular-nums text-xs"
                                    :class="p.conversion_rate !== null && p.conversion_rate >= 0.2 ? 'text-floor-win' : p.conversion_rate !== null ? 'text-deck-soft' : 'text-deck-dim'">
                                    {{ p.conversion_rate !== null ? pct(p.conversion_rate) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="px-4 py-8 text-center text-sm text-deck-dim italic">
                        No partner-site activity yet.
                    </div>
                </div>

                <!-- Compliance posture + Owner signals — two columns -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <!-- Compliance posture -->
                    <div class="deck-card overflow-hidden">
                        <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-deck-text">Compliance posture</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">30-day window</div>
                            </div>
                            <Link href="/compliance" class="text-xs text-floor-accent hover:underline">Open hub →</Link>
                        </header>
                        <div v-if="!compliancePosture" class="px-4 py-8 text-center text-sm text-deck-dim italic">Loading…</div>
                        <div v-else class="p-4 space-y-4">
                            <!-- Pass rate -->
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="deck-label">Disclosure pass rate</div>
                                    <div class="mt-1 deck-num text-3xl"
                                         :class="compliancePosture.disclosure_pass_rate === null ? 'text-deck-dim'
                                             : compliancePosture.disclosure_pass_rate >= compliancePosture.disclosure_target ? 'text-floor-win'
                                             : compliancePosture.disclosure_pass_rate >= 0.85 ? 'text-floor-accent' : 'text-floor-lose'">
                                        {{ pct(compliancePosture.disclosure_pass_rate) }}
                                    </div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        target ≥ {{ pct(compliancePosture.disclosure_target) }}
                                    </div>
                                </div>
                                <div class="text-right text-xs space-y-0.5">
                                    <div class="text-floor-win">{{ compliancePosture.disclosure_breakdown.passed }} passed</div>
                                    <div class="text-floor-accent">{{ compliancePosture.disclosure_breakdown.pending }} pending</div>
                                    <div class="text-floor-lose">{{ compliancePosture.disclosure_breakdown.failed }} failed</div>
                                    <div class="text-floor-lose">{{ compliancePosture.disclosure_breakdown.flagged }} flagged</div>
                                </div>
                            </div>

                            <!-- Open chargebacks (most urgent) -->
                            <div v-if="compliancePosture.open_chargebacks.length" class="border-t border-deck-line/50 pt-3">
                                <div class="deck-label mb-2">Open chargebacks · respond-by</div>
                                <ul class="space-y-1 text-xs">
                                    <li v-for="cb in compliancePosture.open_chargebacks.slice(0, 5)" :key="cb.id"
                                        class="flex items-center justify-between">
                                        <span class="font-mono text-deck-soft">{{ cb.processor_case_id }}</span>
                                        <span class="font-mono" :class="chargebackUrgency(cb.days_until_due)">
                                            {{ cb.respond_by_date }}
                                            <span v-if="cb.days_until_due !== null && cb.days_until_due < 0">OVERDUE</span>
                                            <span v-else-if="cb.days_until_due !== null">({{ cb.days_until_due }}d)</span>
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Inverted closer leaderboard -->
                            <div v-if="compliancePosture.closer_refund_rates.length" class="border-t border-deck-line/50 pt-3">
                                <div class="deck-label mb-2 text-floor-lose">Closers by refund rate · 90d</div>
                                <p class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-2">
                                    inverted leaderboard — bottom of list = coaching priority
                                </p>
                                <ul class="space-y-1 text-xs">
                                    <li v-for="c in compliancePosture.closer_refund_rates.slice(0, 6)" :key="c.id"
                                        class="flex items-center justify-between">
                                        <span class="text-deck-text">{{ c.name }}</span>
                                        <span class="font-mono tabular-nums"
                                              :class="c.refund_rate > 0.05 ? 'text-floor-lose' : c.refund_rate > 0.02 ? 'text-floor-accent' : 'text-deck-soft'">
                                            {{ pct(c.refund_rate) }} ({{ c.refund_cases }}/{{ c.closes }})
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Owner success signals -->
                    <div class="deck-card overflow-hidden">
                        <header class="border-b border-deck-line px-4 py-3">
                            <div class="text-sm font-semibold text-deck-text">Owner success signals</div>
                            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                are owners coming back?
                            </div>
                        </header>
                        <div v-if="!ownerSignals" class="px-4 py-8 text-center text-sm text-deck-dim italic">Loading…</div>
                        <div v-else class="p-4 space-y-4">
                            <div class="grid grid-cols-3 gap-3 text-center">
                                <div>
                                    <div class="deck-label">Re-list rate</div>
                                    <div class="mt-1 deck-num text-2xl"
                                         :class="ownerSignals.relist_rate !== null && ownerSignals.relist_rate >= 0.25 ? 'text-floor-win' : 'text-deck-soft'">
                                        {{ pct(ownerSignals.relist_rate) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="deck-label">Multi-deal</div>
                                    <div class="mt-1 deck-num text-2xl text-floor-win">
                                        {{ ownerSignals.multi_deal_owners }}
                                    </div>
                                    <div class="text-[10px] font-mono text-deck-dim">/ {{ ownerSignals.total_owners_with_deals }}</div>
                                </div>
                                <div>
                                    <div class="deck-label">Avg LTV</div>
                                    <div class="mt-1 deck-num text-2xl">{{ fmtMoney(ownerSignals.avg_lifetime_fees) }}</div>
                                </div>
                            </div>

                            <!-- Upsell opportunities -->
                            <div v-if="ownerSignals.upsell_opportunities.length" class="border-t border-deck-line/50 pt-3">
                                <div class="deck-label mb-2">Untapped inventory · upsell opportunity</div>
                                <ul class="space-y-1 text-xs">
                                    <li v-for="u in ownerSignals.upsell_opportunities" :key="u.owner_id"
                                        class="flex items-center justify-between">
                                        <Link :href="`/owners/${u.owner_id}`" class="text-deck-text hover:text-floor-accent">
                                            {{ u.owner_name }}
                                        </Link>
                                        <span class="font-mono text-deck-soft">
                                            {{ u.active_listings }} of {{ u.properties }} live
                                            <span class="text-floor-accent">· {{ u.untapped }} untapped</span>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ──────────────────────────────────────────────────
                 AI insights + tasks — voiced empty states.
                 ────────────────────────────────────────────────── -->
            <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="deck-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="deck-label text-floor-accent">Floor Intel</span>
                                <span class="pill bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30 font-mono">AI</span>
                            </div>
                            <div class="mt-1 text-base font-semibold text-deck-text">Performance read-out</div>
                        </div>
                        <a href="#" class="text-xs text-floor-accent hover:text-floor-accentHi">Details →</a>
                    </div>
                    <p class="mt-3 text-sm text-deck-soft italic">
                        Floor Intel is warming up — checking in once you've got 30 closed deals on the board.
                    </p>
                </div>

                <div class="deck-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="deck-label">Open tasks</div>
                            <div class="mt-1 text-base font-semibold text-deck-text">Action queue</div>
                        </div>
                        <a href="#" class="text-xs text-floor-accent hover:text-floor-accentHi">View all →</a>
                    </div>
                    <p class="mt-3 text-sm text-deck-soft">
                        <span class="font-mono tabular-nums text-deck-text">0</span> open tasks ·
                        <span class="text-deck-dim italic">all clear.</span>
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
