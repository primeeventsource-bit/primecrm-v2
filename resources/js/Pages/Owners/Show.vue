<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NotesPanel from '@/Components/NotesPanel.vue';
import type { PageProps } from '@/types/api';

/**
 * Owner profile — the customer-service screen for the timeshare listing
 * relationship. Loaded via /api/owners/{id}/dossier (single round-trip
 * aggregate) so the agent who picks up an angry call has every fact in
 * one view: properties owned, listings live, partner-site distribution,
 * renter bookings, financial ledger, open compliance cases.
 */

interface Profile {
    id: string;
    full_name: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    country: string | null;
    state: string | null;
    city: string | null;
    postal_code: string | null;
    timezone: string | null;
    status: string | null;
    priority: string | null;
    score: number | null;
    source: string | null;
    has_express_consent: boolean;
    is_on_dnc: boolean;
    created_at: string | null;
}

interface Metrics {
    total_fees_paid: number;
    total_refunded: number;
    total_charged_back: number;
    net_paid: number;
    agreements_count: number;
    properties_count: number;
    listings_total: number;
    listings_live: number;
    bookings_rented: number;
    commission_earned: number;
    standing: 'active' | 'at_risk' | 'churned' | 'prospect' | 'unknown';
}

interface Property {
    id: string;
    resort_name: string;
    resort_brand: string | null;
    location_city: string;
    location_state: string;
    unit_number: string | null;
    bedrooms: number | null;
    sleeps: number | null;
    view_type: string | null;
    ownership_type: string;
    fixed_week_number: number | null;
    season: string | null;
    ownership_verified: boolean;
    rental_allowed_by_resort: boolean;
    created_at: string | null;
}

interface PartnerSiteRow {
    id: string;
    listing_id: string;
    status: string;
    view_count: number;
    inquiry_count: number;
    external_url: string | null;
    went_live_at: string | null;
    partner_name: string;
    partner_slug: string;
}

interface Listing {
    id: string;
    property_id: string;
    deal_id: string;
    check_in_date: string;
    check_out_date: string;
    asking_price: string;
    owner_payout: string;
    status: string;
    went_live_at: string | null;
    expires_at: string | null;
    created_at: string | null;
    partner_sites: PartnerSiteRow[];
}

interface Agreement {
    id: string;
    agent_id: string | null;
    fronter_id: string | null;
    stage: string;
    agreement_status: string;
    payment_status: string;
    listing_fee: string;
    listing_fee_collected: string;
    total_value: string;
    payable_amount: string;
    tcpa_disclosure_completed: boolean;
    verification_call_completed: boolean;
    agreement_signed_at: string | null;
    closed_at: string | null;
    refund_window_expires_at: string | null;
    term_expires_at: string | null;
    created_at: string | null;
}

interface RentalBooking {
    id: string;
    listing_id: string;
    renter_name: string | null;
    renter_email: string | null;
    check_in_date: string;
    check_out_date: string;
    total_price: string;
    owner_payout: string | null;
    our_commission: string | null;
    status: string;
    payment_status: string;
    owner_notified_at: string | null;
    confirmed_at: string | null;
}

interface PaymentRow {
    id: string;
    deal_id: string | null;
    amount: string;
    currency: string;
    type: string;
    status: string;
    card_brand: string | null;
    card_last_four: string | null;
    cleared_at: string | null;
    refunded_at: string | null;
    failure_reason: string | null;
    created_at: string | null;
}

interface RefundCaseRow {
    id: string;
    deal_id: string;
    refund_amount: string;
    reason: string;
    status: string;
    opened_at: string;
    resolved_at: string | null;
}

interface ChargebackCaseRow {
    id: string;
    deal_id: string;
    disputed_amount: string;
    reason_code: string;
    status: string;
    respond_by_date: string;
}

interface Dossier {
    profile: Profile;
    metrics: Metrics;
    properties: Property[];
    listings: Listing[];
    agreements: Agreement[];
    rental_bookings: RentalBooking[];
    financial_ledger: PaymentRow[];
    cases: { refunds: RefundCaseRow[]; chargebacks: ChargebackCaseRow[] };
}

const props = defineProps<{ ownerId: string }>();

const page = usePage<PageProps>();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);

const dossier = ref<Dossier | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<Dossier>(`/api/owners/${props.ownerId}/dossier`);
        dossier.value = data;
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load this owner.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/* ------------------------------------------------------------------
 | Formatting helpers
 |------------------------------------------------------------------ */

function fmtMoney(n: number | string | null | undefined): string {
    const num = typeof n === 'string' ? parseFloat(n) : (n ?? 0);
    if (!num) return '$0';
    if (num >= 1_000_000) return `$${(num / 1_000_000).toFixed(2)}M`;
    if (num >= 1000) return `$${(num / 1000).toFixed(1)}k`;
    return '$' + Math.round(num).toLocaleString('en-US');
}

function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return iso.split('T')[0];
}

function relabel(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ');
}

const standingColor = computed(() => {
    const standing = dossier.value?.metrics.standing ?? 'unknown';
    return {
        active: 'bg-floor-win/15 text-floor-win ring-floor-win/30',
        at_risk: 'bg-floor-lose/15 text-floor-lose ring-floor-lose/30',
        churned: 'bg-deck-muted text-deck-soft ring-deck-line',
        prospect: 'bg-floor-info/15 text-floor-info ring-floor-info/30',
        unknown: 'bg-deck-muted text-deck-soft ring-deck-line',
    }[standing];
});

function listingStatusColor(s: string): string {
    if (s === 'live' || s === 'inquiry_received' || s === 'pending_booking') return 'text-floor-win';
    if (s === 'booked' || s === 'rented_completed') return 'text-floor-info';
    if (s === 'unrented_expired' || s === 'cancelled') return 'text-floor-lose';
    return 'text-deck-soft';
}

function partnerStatusDot(s: string): string {
    if (s === 'live') return 'bg-floor-win';
    if (s === 'pending') return 'bg-floor-accent';
    if (s === 'paused') return 'bg-amber-500';
    if (s === 'rejected' || s === 'removed') return 'bg-floor-lose';
    return 'bg-deck-dim';
}

function agreementStatusColor(s: string): string {
    if (s === 'live' || s === 'fulfilled') return 'text-floor-win';
    if (s === 'cancelled' || s === 'refunded' || s === 'charged_back') return 'text-floor-lose';
    if (s === 'paid_pending_verification' || s === 'verified_pending_listing') return 'text-floor-accent';
    return 'text-floor-info';
}

function paymentTypeColor(p: PaymentRow): string {
    if (p.type === 'refund') return 'text-floor-lose';
    if (p.type === 'chargeback') return 'text-floor-lose';
    if (p.status === 'failed') return 'text-floor-lose';
    if (p.status === 'succeeded') return 'text-floor-win';
    return 'text-deck-soft';
}

const openRefundCount = computed(() =>
    dossier.value?.cases.refunds.filter((c) => ['opened', 'investigating', 'approved'].includes(c.status)).length ?? 0
);
const openChargebackCount = computed(() =>
    dossier.value?.cases.chargebacks.filter((c) => ['received', 'evidence_gathering', 'evidence_submitted'].includes(c.status)).length ?? 0
);
</script>

<template>
    <AppLayout :title="dossier ? `Owner · ${dossier.profile.full_name}` : 'Owner'">
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <Link href="/leads" class="text-xs text-deck-soft hover:text-deck-text">← Back to leads</Link>
                <Link
                    v-if="dossier"
                    :href="`/leads/${dossier.profile.id}`"
                    class="text-xs text-deck-soft hover:text-deck-text"
                >Sales view →</Link>
            </div>

            <div v-if="loading" class="panel p-6 text-sm text-deck-soft">Loading owner dossier…</div>
            <div v-else-if="error" class="panel p-6 text-sm text-floor-lose">{{ error }}</div>

            <template v-else-if="dossier">
                <!-- ============================== HEADER ============================== -->
                <section class="panel p-5 mb-4">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3 flex-wrap">
                                <h2 class="text-2xl font-semibold text-deck-text">
                                    {{ dossier.profile.full_name }}
                                </h2>
                                <span class="pill ring-1 ring-inset font-mono" :class="standingColor">
                                    {{ relabel(dossier.metrics.standing) }}
                                </span>
                                <span v-if="dossier.profile.is_on_dnc" class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30">DNC</span>
                                <span
                                    v-if="openRefundCount > 0 || openChargebackCount > 0"
                                    class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30"
                                >
                                    {{ openRefundCount + openChargebackCount }} open case{{ openRefundCount + openChargebackCount === 1 ? '' : 's' }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-deck-soft">
                                <span class="font-mono">{{ dossier.profile.phone }}</span>
                                <span v-if="dossier.profile.email">· {{ dossier.profile.email }}</span>
                                <span v-if="dossier.profile.city || dossier.profile.state">
                                    · {{ [dossier.profile.city, dossier.profile.state].filter(Boolean).join(', ') }}
                                </span>
                                <span v-if="dossier.profile.created_at">
                                    · owner since {{ fmtDate(dossier.profile.created_at) }}
                                </span>
                            </div>
                        </div>

                        <!-- Action bar — primary actions for an upset call -->
                        <div class="flex flex-wrap gap-2">
                            <a :href="`tel:${dossier.profile.phone}`" class="btn-ghost text-xs">📞 Call</a>
                            <a v-if="dossier.profile.email" :href="`mailto:${dossier.profile.email}`" class="btn-ghost text-xs">✉ Email</a>
                            <button class="btn-ghost text-xs" disabled title="Coming in D6">+ New agreement</button>
                            <button class="btn-ghost text-xs" disabled title="Coming in D6">Open refund case</button>
                        </div>
                    </div>

                    <!-- KPI strip -->
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                        <div>
                            <div class="deck-label">Net paid</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ fmtMoney(dossier.metrics.net_paid) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Fees collected</div>
                            <div class="mt-1 deck-num text-xl">{{ fmtMoney(dossier.metrics.total_fees_paid) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Refunded</div>
                            <div class="mt-1 deck-num text-xl"
                                 :class="dossier.metrics.total_refunded > 0 ? 'text-floor-lose' : 'text-deck-soft'">
                                {{ dossier.metrics.total_refunded > 0 ? fmtMoney(dossier.metrics.total_refunded) : '—' }}
                            </div>
                        </div>
                        <div>
                            <div class="deck-label">Properties</div>
                            <div class="mt-1 deck-num text-xl">{{ dossier.metrics.properties_count || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Listings live</div>
                            <div class="mt-1 deck-num text-xl text-floor-info">{{ dossier.metrics.listings_live || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Rentals secured</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ dossier.metrics.bookings_rented || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">We earned</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ fmtMoney(dossier.metrics.commission_earned) }}</div>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <!-- ========================== LEFT TWO-THIRDS ========================== -->
                    <div class="space-y-4 xl:col-span-2">
                        <!-- Properties -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Properties owned</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ dossier.properties.length }} timeshare{{ dossier.properties.length === 1 ? '' : 's' }}
                                </div>
                            </header>
                            <div v-if="dossier.properties.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No properties on file. Verification call will gather these before the listing can ship.
                            </div>
                            <ul v-else class="divide-y divide-deck-line/50">
                                <li v-for="p in dossier.properties" :key="p.id" class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm text-deck-text">
                                                <span class="font-medium">{{ p.resort_name }}</span>
                                                <span v-if="p.resort_brand" class="ml-2 text-xs text-deck-soft">{{ p.resort_brand }}</span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-deck-soft">
                                                {{ p.location_city }}, {{ p.location_state }}
                                                · {{ p.bedrooms ?? '?' }}br / sleeps {{ p.sleeps ?? '?' }}
                                                <span v-if="p.view_type">· {{ p.view_type }} view</span>
                                            </div>
                                            <div class="mt-1 flex flex-wrap gap-1.5 text-[10px] font-mono uppercase tracking-wider">
                                                <span class="pill bg-deck-muted text-deck-soft">{{ relabel(p.ownership_type) }}</span>
                                                <span v-if="p.fixed_week_number" class="pill bg-deck-muted text-deck-soft">week {{ p.fixed_week_number }}</span>
                                                <span v-if="p.season && p.season !== 'none'" class="pill bg-deck-muted text-deck-soft">{{ p.season }}</span>
                                                <span class="pill" :class="p.ownership_verified
                                                    ? 'bg-floor-win/15 text-floor-win ring-1 ring-floor-win/30'
                                                    : 'bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30'">
                                                    {{ p.ownership_verified ? '✓ verified' : 'pending verify' }}
                                                </span>
                                                <span v-if="p.ownership_verified && !p.rental_allowed_by_resort"
                                                      class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30">
                                                    rental blocked by resort
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Listings -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div>
                                    <div class="text-sm font-semibold text-deck-text">Listings</div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        {{ dossier.metrics.listings_live }} live · {{ dossier.metrics.listings_total }} total
                                    </div>
                                </div>
                            </header>
                            <div v-if="dossier.listings.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No listings yet. A listing is created from each closed_won listing agreement.
                            </div>
                            <ul v-else class="divide-y divide-deck-line/50">
                                <li v-for="l in dossier.listings" :key="l.id" class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-4 flex-wrap">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 text-sm">
                                                <span class="text-deck-text font-mono tabular-nums">
                                                    {{ fmtDate(l.check_in_date) }} → {{ fmtDate(l.check_out_date) }}
                                                </span>
                                                <span class="text-xs uppercase tracking-wider font-mono"
                                                      :class="listingStatusColor(l.status)">
                                                    · {{ relabel(l.status) }}
                                                </span>
                                            </div>
                                            <div class="mt-0.5 text-xs text-deck-soft">
                                                Asking <span class="font-mono text-deck-text">{{ fmtMoney(l.asking_price) }}</span>
                                                · owner gets <span class="font-mono text-floor-win">{{ fmtMoney(l.owner_payout) }}</span>
                                                <span v-if="l.went_live_at"> · live since {{ fmtDate(l.went_live_at) }}</span>
                                            </div>
                                            <!-- Partner site distribution row -->
                                            <div v-if="l.partner_sites.length" class="mt-2 flex flex-wrap gap-2">
                                                <span v-for="ps in l.partner_sites" :key="ps.id"
                                                      class="inline-flex items-center gap-1.5 rounded-md border border-deck-line bg-deck-bg px-2 py-1 text-[10px] font-mono uppercase tracking-wider text-deck-soft"
                                                      :title="`${ps.partner_name} · ${ps.view_count} views · ${ps.inquiry_count} inquiries`">
                                                    <span class="inline-block h-1.5 w-1.5 rounded-full" :class="partnerStatusDot(ps.status)"></span>
                                                    {{ ps.partner_name }}
                                                    <span v-if="ps.status === 'live'" class="text-deck-dim">· {{ ps.view_count }}v · {{ ps.inquiry_count }}i</span>
                                                    <span v-else class="text-deck-dim">· {{ ps.status }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Agreements -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Listing agreements</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ dossier.agreements.length }} total
                                </div>
                            </header>
                            <div v-if="dossier.agreements.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No listing agreements yet.
                            </div>
                            <table v-else class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-deck-line">
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Stage</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Fee</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Collected</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Compliance</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Signed</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-deck-line/50">
                                    <tr v-for="a in dossier.agreements" :key="a.id" class="hover:bg-deck-raised/40">
                                        <td class="px-4 py-2 font-mono text-xs uppercase tracking-wider"
                                            :class="agreementStatusColor(a.agreement_status)">
                                            {{ relabel(a.agreement_status) }}
                                        </td>
                                        <td class="px-4 py-2 text-xs text-deck-soft">{{ relabel(a.stage) }}</td>
                                        <td class="px-4 py-2 text-right deck-num">{{ fmtMoney(a.listing_fee) }}</td>
                                        <td class="px-4 py-2 text-right deck-num"
                                            :class="parseFloat(a.listing_fee_collected) > 0 ? 'text-floor-win' : 'text-deck-dim'">
                                            {{ fmtMoney(a.listing_fee_collected) }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <span class="text-[10px] font-mono uppercase tracking-wider"
                                                  :class="a.tcpa_disclosure_completed && a.verification_call_completed
                                                      ? 'text-floor-win'
                                                      : a.tcpa_disclosure_completed
                                                          ? 'text-floor-accent'
                                                          : 'text-floor-lose'">
                                                <span v-if="a.tcpa_disclosure_completed && a.verification_call_completed">✓ verified</span>
                                                <span v-else-if="a.tcpa_disclosure_completed">⚑ awaiting verify</span>
                                                <span v-else>⚠ disclosures missing</span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 text-xs font-mono tabular-nums text-deck-soft">
                                            {{ fmtDate(a.agreement_signed_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <!-- Renter bookings -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Renter bookings</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ dossier.rental_bookings.length }} booking{{ dossier.rental_bookings.length === 1 ? '' : 's' }} · we earned {{ fmtMoney(dossier.metrics.commission_earned) }}
                                </div>
                            </header>
                            <div v-if="dossier.rental_bookings.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No rental bookings yet — this is the success metric the owner is waiting for.
                            </div>
                            <table v-else class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-deck-line">
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Renter</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Dates</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Total</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Owner gets</th>
                                        <th class="px-4 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">We earn</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                                        <th class="px-4 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Notified?</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-deck-line/50">
                                    <tr v-for="b in dossier.rental_bookings" :key="b.id" class="hover:bg-deck-raised/40">
                                        <td class="px-4 py-2 text-deck-text">{{ b.renter_name ?? '—' }}</td>
                                        <td class="px-4 py-2 font-mono tabular-nums text-xs text-deck-soft">
                                            {{ fmtDate(b.check_in_date) }} → {{ fmtDate(b.check_out_date) }}
                                        </td>
                                        <td class="px-4 py-2 text-right deck-num">{{ fmtMoney(b.total_price) }}</td>
                                        <td class="px-4 py-2 text-right deck-num">{{ fmtMoney(b.owner_payout) }}</td>
                                        <td class="px-4 py-2 text-right deck-num text-floor-win">{{ fmtMoney(b.our_commission) }}</td>
                                        <td class="px-4 py-2 text-xs uppercase tracking-wider font-mono text-deck-soft">{{ relabel(b.status) }}</td>
                                        <td class="px-4 py-2 text-xs">
                                            <span v-if="b.owner_notified_at" class="text-floor-win">✓ {{ fmtDate(b.owner_notified_at) }}</span>
                                            <span v-else class="text-floor-lose">not yet</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <!-- Financial ledger -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Financial ledger</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ dossier.financial_ledger.length }} entries
                                </div>
                            </header>
                            <div v-if="dossier.financial_ledger.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No financial activity yet.
                            </div>
                            <ul v-else class="divide-y divide-deck-line/50">
                                <li v-for="p in dossier.financial_ledger" :key="p.id"
                                    class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-deck-raised/40 text-sm">
                                    <div class="min-w-0 flex-1">
                                        <div :class="paymentTypeColor(p)" class="font-mono text-xs uppercase tracking-wider">
                                            {{ relabel(p.type) }} · {{ relabel(p.status) }}
                                        </div>
                                        <div class="text-xs text-deck-dim">
                                            <span v-if="p.card_brand && p.card_last_four">{{ p.card_brand }} •••• {{ p.card_last_four }}</span>
                                            <span v-if="p.failure_reason"> · {{ p.failure_reason }}</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="deck-num" :class="paymentTypeColor(p)">
                                            <span v-if="p.type === 'refund' || p.type === 'chargeback'">−</span>{{ fmtMoney(p.amount) }}
                                        </div>
                                        <div class="text-[10px] font-mono tabular-nums text-deck-dim">
                                            {{ fmtDate(p.cleared_at ?? p.refunded_at ?? p.created_at) }}
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <!-- =========================== RIGHT THIRD =========================== -->
                    <div class="space-y-4 xl:col-span-1">
                        <!-- Open cases — most urgent first -->
                        <section v-if="openRefundCount + openChargebackCount > 0" class="panel border-l-2 border-floor-lose">
                            <header class="border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-floor-lose">Open cases</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">action required</div>
                            </header>
                            <ul class="divide-y divide-deck-line/50 text-sm">
                                <li v-for="c in dossier.cases.refunds.filter((r) => ['opened', 'investigating', 'approved'].includes(r.status))"
                                    :key="c.id" class="px-4 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-deck-text">Refund — {{ relabel(c.reason) }}</span>
                                        <span class="deck-num text-floor-lose">{{ fmtMoney(c.refund_amount) }}</span>
                                    </div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        {{ relabel(c.status) }} · opened {{ fmtDate(c.opened_at) }}
                                    </div>
                                </li>
                                <li v-for="c in dossier.cases.chargebacks.filter((cb) => ['received', 'evidence_gathering', 'evidence_submitted'].includes(cb.status))"
                                    :key="c.id" class="px-4 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-deck-text">Chargeback {{ c.reason_code }}</span>
                                        <span class="deck-num text-floor-lose">{{ fmtMoney(c.disputed_amount) }}</span>
                                    </div>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        {{ relabel(c.status) }} · respond by {{ c.respond_by_date }}
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Communication timeline -->
                        <NotesPanel
                            notable-type="lead"
                            :notable-id="dossier.profile.id"
                            :current-user-id="currentUserId"
                        />

                        <!-- Profile facts (smaller, below NotesPanel) -->
                        <section class="panel p-4">
                            <h3 class="mb-3 text-sm font-semibold text-deck-text">Profile</h3>
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Source</dt><dd class="text-deck-text">{{ dossier.profile.source ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Score</dt><dd class="text-deck-text">{{ dossier.profile.score ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Alt phone</dt><dd class="text-deck-text font-mono">{{ dossier.profile.alternate_phone ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Postal</dt><dd class="text-deck-text">{{ dossier.profile.postal_code ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Timezone</dt><dd class="text-deck-text">{{ dossier.profile.timezone ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-deck-soft">Express consent</dt><dd>{{ dossier.profile.has_express_consent ? '✓' : '—' }}</dd></div>
                            </dl>
                        </section>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
