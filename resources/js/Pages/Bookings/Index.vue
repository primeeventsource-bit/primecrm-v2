<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddBookingModal from '@/Components/Bookings/AddBookingModal.vue';

/**
 * Bookings ledger — confirmed rentals across all listings.
 *
 * The success-metric view: how much money flowed, how much owners
 * received, how much we earned, and — the service-quality column —
 * whether we actually notified the owner that their week rented.
 */

type Range = 'this_week' | 'this_month' | 'last_30' | 'last_90' | 'all';

interface BookingRow {
    id: string;
    confirmation_number: string;
    status: string;
    payment_status: string;
    renter_name: string | null;
    renter_email: string | null;
    check_in_date: string;
    check_out_date: string;
    total_price: number;
    owner_payout: number | null;
    our_commission: number | null;
    confirmed_at: string | null;
    owner_notified_at: string | null;
    listing: {
        id: string;
        resort_name: string;
        resort_brand: string | null;
        location_city: string;
        location_state: string;
    };
    owner: { id: string; name: string };
    closer: { id: string; name: string } | null;
}

interface Totals {
    bookings_count: number;
    total_rental_value: number;
    total_owner_payout: number;
    total_commission: number;
    owners_notified: number;
}

interface ApiResponse {
    data: BookingRow[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
    totals: Totals;
}

const range = ref<Range>('this_month');
const stateFilter = ref('');
const brandFilter = ref('');
const statusFilter = ref('');
const search = ref('');
const page = ref(1);
const perPage = ref(25);

const rows = ref<BookingRow[]>([]);
const meta = ref<ApiResponse['meta'] | null>(null);
const totals = ref<Totals | null>(null);
const loading = ref(false);

let searchTimer: number | undefined;

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<ApiResponse>('/api/rental-bookings', {
            params: {
                range: range.value,
                state: stateFilter.value || undefined,
                brand: brandFilter.value || undefined,
                status: statusFilter.value || undefined,
                q: search.value || undefined,
                page: page.value,
                per_page: perPage.value,
            },
        });
        rows.value = data.data;
        meta.value = data.meta;
        totals.value = data.totals;
    } finally {
        loading.value = false;
    }
}

watch([range, stateFilter, brandFilter, statusFilter, page], () => void load());
watch(search, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        page.value = 1;
        void load();
    }, 250);
});

onMounted(load);

const ranges: Array<{ key: Range; label: string }> = [
    { key: 'this_week', label: 'This week' },
    { key: 'this_month', label: 'This month' },
    { key: 'last_30', label: 'Last 30 days' },
    { key: 'last_90', label: 'Last 90 days' },
    { key: 'all', label: 'All time' },
];

const notifyRate = computed(() => {
    if (!totals.value || totals.value.bookings_count === 0) return null;
    return totals.value.owners_notified / totals.value.bookings_count;
});

function fmtMoney(n: number | null | undefined): string {
    if (n == null) return '—';
    if (!n) return '$0';
    if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(2)}M`;
    if (n >= 1000) return `$${(n / 1000).toFixed(1)}k`;
    return '$' + Math.round(n).toLocaleString('en-US');
}
function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return iso.split('T')[0];
}
function pct(n: number | null | undefined): string {
    if (n == null) return '—';
    return (n * 100).toFixed(1) + '%';
}
function relabel(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ');
}
function statusColor(s: string): string {
    if (s === 'confirmed' || s === 'paid' || s === 'completed') return 'text-floor-win';
    if (s === 'checked_in') return 'text-floor-info';
    if (s === 'cancelled' || s === 'no_show' || s === 'refunded') return 'text-floor-lose';
    return 'text-deck-soft';
}
function paymentColor(s: string): string {
    if (s === 'paid_in_full' || s === 'paid') return 'text-floor-win';
    if (s === 'deposit_paid') return 'text-floor-info';
    if (s === 'refunded') return 'text-floor-lose';
    return 'text-floor-accent';
}

function gotoListing(id: string): void {
    router.visit(`/listings/${id}`);
}

const lastPage = computed(() => meta.value?.last_page ?? 1);

const createOpen = ref(false);

function onBookingCreated(): void {
    createOpen.value = false;
    // Refresh the ledger so the new booking appears + the aggregate
    // strip updates immediately.
    page.value = 1;
    void load();
}
</script>

<template>
    <AppLayout title="Bookings">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-4 flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h1 class="text-2xl font-semibold text-deck-text">Bookings</h1>
                    <p class="text-sm text-deck-soft">
                        Confirmed rentals across all listings — the honest measure of whether the service works.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="search renter / confirmation / owner / resort"
                        class="input text-sm w-72"
                    />
                    <button class="btn-primary text-sm whitespace-nowrap" @click="createOpen = true">
                        + Add booking
                    </button>
                </div>
            </div>

            <AddBookingModal
                :open="createOpen"
                @close="createOpen = false"
                @created="onBookingCreated"
                @imported="onBookingCreated"
            />

            <!-- Aggregate strip -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                <div class="deck-card p-4">
                    <div class="deck-label">Bookings</div>
                    <div class="mt-1 deck-num text-2xl">{{ totals?.bookings_count || '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Rental value</div>
                    <div class="mt-1 deck-num text-2xl">{{ totals ? fmtMoney(totals.total_rental_value) : '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Owners earned</div>
                    <div class="mt-1 deck-num text-2xl text-floor-info">{{ totals ? fmtMoney(totals.total_owner_payout) : '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">We earned</div>
                    <div class="mt-1 deck-num text-2xl text-floor-win">{{ totals ? fmtMoney(totals.total_commission) : '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Owners notified</div>
                    <div class="mt-1 deck-num text-2xl"
                         :class="notifyRate !== null && notifyRate < 0.9 ? 'text-floor-lose' : 'text-floor-win'">
                        {{ notifyRate !== null ? pct(notifyRate) : '—' }}
                    </div>
                    <div class="text-[10px] font-mono text-deck-dim mt-0.5">
                        <span v-if="totals">{{ totals.owners_notified }} / {{ totals.bookings_count }}</span>
                    </div>
                </div>
            </div>

            <!-- Range tabs -->
            <div class="flex gap-1 border-b border-deck-line mb-4">
                <button
                    v-for="r in ranges"
                    :key="r.key"
                    class="px-4 py-2 text-sm border-b-2 -mb-px transition-colors"
                    :class="range === r.key
                        ? 'border-floor-accent text-deck-text font-medium'
                        : 'border-transparent text-deck-dim hover:text-deck-soft'"
                    @click="(range = r.key, page = 1)"
                >{{ r.label }}</button>
            </div>

            <!-- Secondary filters -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4 mb-3 text-xs">
                <div>
                    <label class="label">State</label>
                    <input v-model="stateFilter" maxlength="2" placeholder="FL" class="input mt-1 text-sm uppercase" />
                </div>
                <div>
                    <label class="label">Resort brand</label>
                    <input v-model="brandFilter" placeholder="Marriott / Wyndham / ..." class="input mt-1 text-sm" />
                </div>
                <div>
                    <label class="label">Status</label>
                    <select v-model="statusFilter" class="input mt-1 text-sm">
                        <option value="">Any</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="paid">Paid</option>
                        <option value="checked_in">Checked in</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="no_show">No show</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <span class="text-deck-dim text-[10px] uppercase font-mono tracking-wider">
                        {{ meta?.total ?? 0 }} matching booking{{ meta?.total === 1 ? '' : 's' }}
                    </span>
                </div>
            </div>

            <!-- Table -->
            <div class="panel overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-deck-line">
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Renter / Confirmation</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Resort / Owner</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Dates</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Total</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Owner gets</th>
                            <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">We earn</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Closer</th>
                            <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Notified?</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-deck-line/50">
                        <tr v-if="!loading && rows.length === 0">
                            <td colspan="9" class="px-3 py-12 text-center text-sm text-deck-dim italic">
                                No bookings in this window. The success metric of the listing service goes here once renters land.
                            </td>
                        </tr>
                        <tr
                            v-for="b in rows"
                            :key="b.id"
                            class="cursor-pointer hover:bg-deck-raised/40"
                            @click="gotoListing(b.listing.id)"
                        >
                            <td class="px-3 py-2">
                                <div class="text-sm text-deck-text">{{ b.renter_name ?? '—' }}</div>
                                <div class="text-[10px] font-mono text-deck-dim">{{ b.confirmation_number }}</div>
                            </td>
                            <td class="px-3 py-2" @click.stop>
                                <Link :href="`/listings/${b.listing.id}`" class="text-sm text-deck-text hover:text-floor-accent">
                                    {{ b.listing.resort_name }}
                                </Link>
                                <div class="text-[11px] text-deck-soft">
                                    <Link :href="`/owners/${b.owner.id}`" class="hover:text-floor-accent">{{ b.owner.name }}</Link>
                                    · {{ b.listing.location_state }}
                                </div>
                            </td>
                            <td class="px-3 py-2 font-mono tabular-nums text-xs text-deck-soft whitespace-nowrap">
                                {{ fmtDate(b.check_in_date) }}<br>
                                <span class="text-deck-dim">→ {{ fmtDate(b.check_out_date) }}</span>
                            </td>
                            <td class="px-3 py-2 text-right deck-num">{{ fmtMoney(b.total_price) }}</td>
                            <td class="px-3 py-2 text-right deck-num text-floor-info">{{ fmtMoney(b.owner_payout) }}</td>
                            <td class="px-3 py-2 text-right deck-num text-floor-win">{{ fmtMoney(b.our_commission) }}</td>
                            <td class="px-3 py-2">
                                <div class="font-mono text-xs uppercase tracking-wider" :class="statusColor(b.status)">
                                    {{ relabel(b.status) }}
                                </div>
                                <div class="font-mono text-[10px] uppercase tracking-wider" :class="paymentColor(b.payment_status)">
                                    {{ relabel(b.payment_status) }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs text-deck-soft">
                                {{ b.closer?.name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span v-if="b.owner_notified_at" class="text-floor-win">✓ {{ fmtDate(b.owner_notified_at) }}</span>
                                <span v-else class="text-floor-lose">⚠ not yet</span>
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
