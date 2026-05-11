<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';

/**
 * Listing detail — the operational view of one marketed offering.
 *
 *   Property card  +  Owner card
 *   Listing terms (dates, asking, payout, commission)
 *   Partner-site grid (one card per site, status + URL + counters)
 *   Inquiries inbox  (renter messages, respond buttons stubbed for D5)
 *   Booking         (if a renter actually booked — the success row)
 *   Activity log    (synthesized timeline of the listing's lifecycle)
 */

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
}
interface Owner {
    id: string;
    full_name: string;
    email: string | null;
    phone: string | null;
    location: string | null;
}
interface AgreementSummary {
    id: string;
    listing_fee: number | null;
    payment_status: string | null;
    agreement_status: string | null;
    agreement_signed_at: string | null;
}
interface PartnerSite {
    id: string;
    partner_site_id: string;
    name: string;
    slug: string;
    our_cost_per_listing: number | null;
    status: string;
    external_listing_id: string | null;
    external_url: string | null;
    view_count: number;
    inquiry_count: number;
    rejection_reason: string | null;
    pushed_at: string | null;
    went_live_at: string | null;
    last_synced_at: string | null;
}
interface Inquiry {
    id: string;
    renter_name: string;
    renter_email: string | null;
    renter_phone: string | null;
    requested_check_in: string | null;
    requested_check_out: string | null;
    offered_amount: number | null;
    message: string | null;
    status: string;
    responded_at: string | null;
    created_at: string | null;
    partner_name: string | null;
    handler_name: string | null;
}
interface Booking {
    id: string;
    renter_name: string | null;
    renter_email: string | null;
    renter_phone: string | null;
    check_in_date: string;
    check_out_date: string;
    total_price: number;
    owner_payout: number | null;
    our_commission: number | null;
    status: string;
    payment_status: string;
    confirmation_number: string;
    confirmed_at: string | null;
    owner_notified_at: string | null;
}
interface ActivityEntry {
    kind: string;
    label: string;
    occurred_at: string;
}
interface Detail {
    id: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
    asking_price: number;
    reserve_price: number | null;
    owner_payout: number;
    our_commission_pct: number | null;
    went_live_at: string | null;
    expires_at: string | null;
    created_at: string | null;
    marketing_description: string | null;
    photos: string[] | null;
    property: Property;
    owner: Owner;
    agreement: AgreementSummary;
    partner_sites: PartnerSite[];
    inquiries: Inquiry[];
    booking: Booking | null;
    activity: ActivityEntry[];
}

const props = defineProps<{ listingId: string }>();

const detail = ref<Detail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const flash = ref<{ kind: 'ok' | 'err'; msg: string } | null>(null);

// Distribution-action busy state per partner_site_listing.id, so a
// single row can show "working…" without freezing the whole grid.
const busyRowId = ref<string | null>(null);

// Pickable partner sites for "+ Add to site" — populated lazily when
// the picker opens, filtered to ones we haven't pushed to yet.
interface PickableSite { id: string; name: string; slug: string }
const allSites = ref<PickableSite[]>([]);
const showAddPicker = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<Detail>(`/api/listings/${props.listingId}`);
        detail.value = data;
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load this listing.';
    } finally {
        loading.value = false;
    }
}

async function loadSites(): Promise<void> {
    if (allSites.value.length > 0) return;
    try {
        const { data } = await axios.get<{ data: PickableSite[] }>('/api/partner-sites');
        allSites.value = data.data;
    } catch {
        allSites.value = [];
    }
}

async function distributionAction(rowId: string, action: 'repush' | 'pause' | 'resume' | 'sync'): Promise<void> {
    if (!detail.value) return;
    busyRowId.value = rowId;
    flash.value = null;
    try {
        const { data } = await axios.post<{ message: string }>(
            `/api/listings/${props.listingId}/distributions/${rowId}/${action}`,
        );
        flash.value = { kind: 'ok', msg: data.message };
        await load();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        flash.value = { kind: 'err', msg: msg ?? 'Action failed.' };
    } finally {
        busyRowId.value = null;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

async function addToSite(siteId: string): Promise<void> {
    if (!detail.value) return;
    busyRowId.value = 'new';
    flash.value = null;
    try {
        const { data } = await axios.post<{ message: string }>(
            `/api/listings/${props.listingId}/distributions`,
            { partner_site_id: siteId },
        );
        flash.value = { kind: 'ok', msg: data.message };
        showAddPicker.value = false;
        await load();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        flash.value = { kind: 'err', msg: msg ?? 'Could not add to partner site.' };
    } finally {
        busyRowId.value = null;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

const pickableSites = computed(() => {
    if (!detail.value) return [];
    const already = new Set(detail.value.partner_sites.map((p) => p.partner_site_id));
    return allSites.value.filter((s) => !already.has(s.id));
});

/* ------------------------------------------------------------------
 | Inquiry workflow (D5)
 |------------------------------------------------------------------ */

type InquiryMode = 'respond' | 'book';

const inquiryModalOpen = ref(false);
const inquiryMode = ref<InquiryMode>('respond');
const activeInquiry = ref<Inquiry | null>(null);
const respondMessage = ref('');
const respondAmount = ref<number | null>(null);
const bookForm = ref({
    check_in_date: '' as string,
    check_out_date: '' as string,
    total_price: 0 as number,
    renter_name: '' as string,
    renter_email: '' as string,
    renter_phone: '' as string,
});
const inquiryBusy = ref(false);

function openRespondModal(iq: Inquiry): void {
    activeInquiry.value = iq;
    inquiryMode.value = 'respond';
    respondMessage.value = '';
    respondAmount.value = iq.offered_amount;
    inquiryModalOpen.value = true;
}

function openBookModal(iq: Inquiry): void {
    if (!detail.value) return;
    activeInquiry.value = iq;
    inquiryMode.value = 'book';
    bookForm.value = {
        check_in_date: iq.requested_check_in ?? detail.value.check_in_date,
        check_out_date: iq.requested_check_out ?? detail.value.check_out_date,
        total_price: iq.offered_amount ?? detail.value.asking_price,
        renter_name: iq.renter_name,
        renter_email: iq.renter_email ?? '',
        renter_phone: iq.renter_phone ?? '',
    };
    inquiryModalOpen.value = true;
}

async function submitRespond(): Promise<void> {
    if (!activeInquiry.value) return;
    inquiryBusy.value = true;
    flash.value = null;
    try {
        const { data } = await axios.post<{ message: string }>(
            `/api/rental-inquiries/${activeInquiry.value.id}/respond`,
            {
                message: respondMessage.value,
                offered_amount: respondAmount.value || null,
            },
        );
        flash.value = { kind: 'ok', msg: data.message };
        inquiryModalOpen.value = false;
        await load();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        flash.value = { kind: 'err', msg: msg ?? 'Could not send response.' };
    } finally {
        inquiryBusy.value = false;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

async function submitBook(): Promise<void> {
    if (!activeInquiry.value) return;
    inquiryBusy.value = true;
    flash.value = null;
    try {
        const { data } = await axios.post<{ message: string }>(
            `/api/rental-inquiries/${activeInquiry.value.id}/book`,
            bookForm.value,
        );
        flash.value = { kind: 'ok', msg: data.message };
        inquiryModalOpen.value = false;
        await load();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        flash.value = { kind: 'err', msg: msg ?? 'Could not create booking.' };
    } finally {
        inquiryBusy.value = false;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

async function markInquiryLost(id: string): Promise<void> {
    inquiryBusy.value = true;
    flash.value = null;
    try {
        const { data } = await axios.post<{ message: string }>(
            `/api/rental-inquiries/${id}/mark-lost`,
        );
        flash.value = { kind: 'ok', msg: data.message };
        await load();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        flash.value = { kind: 'err', msg: msg ?? 'Could not mark lost.' };
    } finally {
        inquiryBusy.value = false;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

onMounted(load);

/* ------------------------------------------------------------------ */

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
function relabel(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ');
}
function statusColor(s: string): string {
    if (s === 'live' || s === 'inquiry_received' || s === 'pending_booking') return 'text-floor-win';
    if (s === 'booked' || s === 'rented_completed') return 'text-floor-info';
    if (s === 'unrented_expired' || s === 'cancelled') return 'text-floor-lose';
    return 'text-deck-soft';
}
function partnerCardBorder(s: string): string {
    if (s === 'live') return 'border-floor-win/40';
    if (s === 'pending') return 'border-floor-accent/40';
    if (s === 'paused') return 'border-amber-500/40';
    if (s === 'rejected' || s === 'removed') return 'border-floor-lose/40';
    return 'border-deck-line';
}
function partnerStatusPill(s: string): string {
    if (s === 'live') return 'bg-floor-win/15 text-floor-win ring-1 ring-floor-win/30';
    if (s === 'pending') return 'bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30';
    if (s === 'paused') return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30';
    if (s === 'rejected' || s === 'removed') return 'bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30';
    return 'bg-deck-muted text-deck-soft ring-1 ring-deck-line';
}
function inquiryStatusPill(s: string): string {
    if (s === 'new') return 'bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30';
    if (s === 'responded' || s === 'negotiating') return 'bg-floor-info/15 text-floor-info ring-1 ring-floor-info/30';
    if (s === 'booked') return 'bg-floor-win/15 text-floor-win ring-1 ring-floor-win/30';
    if (s === 'lost') return 'bg-deck-muted text-deck-soft ring-1 ring-deck-line';
    return 'bg-deck-muted text-deck-soft ring-1 ring-deck-line';
}
function activityIcon(kind: string): string {
    return {
        listing_created: '◯',
        listing_live: '●',
        partner_pushed: '↑',
        partner_live: '✓',
        partner_rejected: '✕',
        inquiry: '✉',
        booked: '$',
        owner_notified: '✓',
    }[kind] ?? '·';
}
function activityColor(kind: string): string {
    return {
        listing_live: 'text-floor-win',
        partner_live: 'text-floor-win',
        partner_rejected: 'text-floor-lose',
        inquiry: 'text-floor-accent',
        booked: 'text-floor-win',
        owner_notified: 'text-floor-info',
    }[kind] ?? 'text-deck-soft';
}
</script>

<template>
    <AppLayout :title="detail ? `Listing · ${detail.property.resort_name}` : 'Listing'">
        <div class="p-6">
            <div class="mb-4">
                <Link href="/listings" class="text-xs text-deck-soft hover:text-deck-text">← Back to listings</Link>
            </div>

            <div v-if="loading" class="panel p-6 text-sm text-deck-soft">Loading listing…</div>
            <div v-else-if="error" class="panel p-6 text-sm text-floor-lose">{{ error }}</div>

            <template v-else-if="detail">
                <!-- ============================== HEADER ============================== -->
                <section class="panel p-5 mb-4">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-2xl font-semibold text-deck-text">
                                {{ detail.property.resort_name }}
                            </h2>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-deck-soft">
                                <span v-if="detail.property.resort_brand">{{ detail.property.resort_brand }}</span>
                                <span>· {{ detail.property.location_city }}, {{ detail.property.location_state }}</span>
                                <span>· {{ detail.property.bedrooms ?? '?' }}br / sleeps {{ detail.property.sleeps ?? '?' }}</span>
                                <span v-if="detail.property.view_type">· {{ detail.property.view_type }} view</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="pill ring-1 ring-inset font-mono"
                                      :class="statusColor(detail.status) + ' bg-deck-muted'">
                                    {{ relabel(detail.status) }}
                                </span>
                                <span class="pill bg-deck-muted text-deck-soft ring-1 ring-deck-line">
                                    {{ relabel(detail.property.ownership_type) }}
                                </span>
                                <span v-if="detail.property.fixed_week_number" class="pill bg-deck-muted text-deck-soft ring-1 ring-deck-line">
                                    week {{ detail.property.fixed_week_number }}
                                </span>
                                <span v-if="detail.property.season && detail.property.season !== 'none'" class="pill bg-deck-muted text-deck-soft ring-1 ring-deck-line">
                                    {{ detail.property.season }}
                                </span>
                                <span v-if="!detail.property.ownership_verified"
                                      class="pill bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30">
                                    pending verify
                                </span>
                                <span v-else-if="!detail.property.rental_allowed_by_resort"
                                      class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30">
                                    rental blocked
                                </span>
                                <span v-else class="pill bg-floor-win/15 text-floor-win ring-1 ring-floor-win/30">
                                    ✓ verified
                                </span>
                            </div>
                        </div>

                        <!-- Owner card -->
                        <div class="min-w-[200px] rounded-md border border-deck-line bg-deck-bg p-3">
                            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-1">Owner</div>
                            <Link :href="`/owners/${detail.owner.id}`" class="text-sm font-semibold text-floor-accent hover:underline">
                                {{ detail.owner.full_name }}
                            </Link>
                            <div v-if="detail.owner.location" class="text-xs text-deck-soft mt-0.5">{{ detail.owner.location }}</div>
                            <div class="mt-1.5 flex gap-2 text-xs">
                                <a v-if="detail.owner.phone" :href="`tel:${detail.owner.phone}`" class="text-deck-soft hover:text-deck-text font-mono">📞</a>
                                <a v-if="detail.owner.email" :href="`mailto:${detail.owner.email}`" class="text-deck-soft hover:text-deck-text">✉</a>
                            </div>
                        </div>
                    </div>

                    <!-- KPI strip -->
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        <div>
                            <div class="deck-label">Check-in</div>
                            <div class="mt-1 deck-num text-sm">{{ fmtDate(detail.check_in_date) }}</div>
                            <div class="text-[10px] font-mono text-deck-dim">→ {{ fmtDate(detail.check_out_date) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Asking</div>
                            <div class="mt-1 deck-num text-xl">{{ fmtMoney(detail.asking_price) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Owner gets</div>
                            <div class="mt-1 deck-num text-xl text-floor-win">{{ fmtMoney(detail.owner_payout) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Reserve</div>
                            <div class="mt-1 deck-num text-sm">{{ detail.reserve_price ? fmtMoney(detail.reserve_price) : '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Live since</div>
                            <div class="mt-1 deck-num text-sm">{{ fmtDate(detail.went_live_at) }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Window expires</div>
                            <div class="mt-1 deck-num text-sm">{{ fmtDate(detail.expires_at) }}</div>
                        </div>
                    </div>

                    <p v-if="detail.marketing_description" class="mt-4 text-sm text-deck-soft italic">
                        "{{ detail.marketing_description }}"
                    </p>
                </section>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <!-- ========================== LEFT TWO-THIRDS ========================== -->
                    <div class="space-y-4 xl:col-span-2">
                        <!-- Partner-site grid — visual centerpiece -->
                        <section>
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <h3 class="text-sm font-semibold text-deck-text">Partner sites</h3>
                                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                        {{ detail.partner_sites.length }} push{{ detail.partner_sites.length === 1 ? '' : 'es' }}
                                    </div>
                                </div>
                                <button
                                    class="btn-ghost text-xs"
                                    :disabled="busyRowId !== null"
                                    @click="(showAddPicker = !showAddPicker, loadSites())"
                                >+ Add to partner site</button>
                            </div>

                            <!-- Flash bar for distribution actions -->
                            <div v-if="flash"
                                 class="mb-2 rounded-md px-3 py-2 text-xs"
                                 :class="flash.kind === 'ok'
                                     ? 'border border-floor-win/30 bg-floor-win/10 text-floor-win'
                                     : 'border border-floor-lose/30 bg-floor-lose/10 text-floor-lose'">
                                {{ flash.msg }}
                            </div>

                            <!-- Add-to-site picker -->
                            <div v-if="showAddPicker" class="deck-card p-3 mb-3">
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-2">
                                    Pick a site
                                </div>
                                <div v-if="pickableSites.length === 0" class="text-sm text-deck-dim italic">
                                    Already pushed to every active partner site.
                                </div>
                                <div v-else class="flex flex-wrap gap-2">
                                    <button
                                        v-for="s in pickableSites"
                                        :key="s.id"
                                        class="btn-ghost text-xs"
                                        :disabled="busyRowId === 'new'"
                                        @click="addToSite(s.id)"
                                    >
                                        {{ s.name }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="detail.partner_sites.length === 0 && !showAddPicker" class="panel p-6 text-center text-sm text-deck-dim italic">
                                Not pushed to any partner sites yet. Use "+ Add to partner site" above to push this listing.
                            </div>
                            <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div
                                    v-for="ps in detail.partner_sites"
                                    :key="ps.id"
                                    class="deck-card p-4 border-l-2"
                                    :class="partnerCardBorder(ps.status)"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-deck-text">{{ ps.name }}</div>
                                            <div v-if="ps.external_listing_id" class="text-[10px] font-mono text-deck-dim mt-0.5">
                                                ID {{ ps.external_listing_id }}
                                            </div>
                                        </div>
                                        <span class="pill font-mono" :class="partnerStatusPill(ps.status)">
                                            {{ ps.status }}
                                        </span>
                                    </div>

                                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <div class="deck-label">Views</div>
                                            <div class="deck-num text-base">{{ ps.view_count || '—' }}</div>
                                        </div>
                                        <div>
                                            <div class="deck-label">Inquiries</div>
                                            <div class="deck-num text-base"
                                                 :class="ps.inquiry_count > 0 ? 'text-floor-accent' : ''">
                                                {{ ps.inquiry_count || '—' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="ps.rejection_reason" class="mt-2 text-xs text-floor-lose italic">
                                        {{ ps.rejection_reason }}
                                    </div>

                                    <div class="mt-3 flex items-center justify-between text-[10px] font-mono text-deck-dim">
                                        <div>
                                            <div v-if="ps.went_live_at">live {{ fmtDate(ps.went_live_at) }}</div>
                                            <div v-else-if="ps.pushed_at">pushed {{ fmtDate(ps.pushed_at) }}</div>
                                        </div>
                                        <div v-if="ps.last_synced_at">synced {{ fmtDate(ps.last_synced_at) }}</div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2 items-center">
                                        <a
                                            v-if="ps.external_url"
                                            :href="ps.external_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-xs text-floor-accent hover:underline"
                                        >Open external →</a>
                                        <button
                                            class="text-xs text-deck-soft hover:text-deck-text disabled:opacity-40"
                                            :disabled="busyRowId !== null"
                                            @click="distributionAction(ps.id, 'repush')"
                                        >Re-push</button>
                                        <button
                                            v-if="ps.status === 'live'"
                                            class="text-xs text-deck-soft hover:text-deck-text disabled:opacity-40"
                                            :disabled="busyRowId !== null"
                                            @click="distributionAction(ps.id, 'pause')"
                                        >Pause</button>
                                        <button
                                            v-else-if="ps.status === 'paused'"
                                            class="text-xs text-floor-win hover:text-floor-win/80 disabled:opacity-40"
                                            :disabled="busyRowId !== null"
                                            @click="distributionAction(ps.id, 'resume')"
                                        >Resume</button>
                                        <button
                                            class="text-xs text-deck-soft hover:text-deck-text disabled:opacity-40"
                                            :disabled="busyRowId !== null"
                                            @click="distributionAction(ps.id, 'sync')"
                                        >Sync</button>
                                        <span v-if="busyRowId === ps.id" class="text-[10px] font-mono uppercase tracking-wider text-floor-accent">working…</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Inquiries inbox -->
                        <section class="panel">
                            <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Inquiries</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ detail.inquiries.length }} total
                                </div>
                            </header>
                            <div v-if="detail.inquiries.length === 0" class="px-4 py-8 text-center text-sm text-deck-dim italic">
                                No inquiries yet. Renter messages from partner sites land here.
                            </div>
                            <ul v-else class="divide-y divide-deck-line/50">
                                <li v-for="iq in detail.inquiries" :key="iq.id" class="px-4 py-3">
                                    <div class="flex items-start justify-between gap-3 flex-wrap">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-deck-text">{{ iq.renter_name }}</span>
                                                <span class="pill font-mono" :class="inquiryStatusPill(iq.status)">
                                                    {{ iq.status }}
                                                </span>
                                                <span v-if="iq.partner_name" class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                                    via {{ iq.partner_name }}
                                                </span>
                                            </div>
                                            <div class="text-xs text-deck-soft mt-0.5">
                                                <span v-if="iq.requested_check_in" class="font-mono tabular-nums">
                                                    {{ fmtDate(iq.requested_check_in) }} → {{ fmtDate(iq.requested_check_out) }}
                                                </span>
                                                <span v-if="iq.offered_amount"> · offered {{ fmtMoney(iq.offered_amount) }}</span>
                                            </div>
                                            <p v-if="iq.message" class="mt-1 text-sm text-deck-text">"{{ iq.message }}"</p>
                                            <div class="mt-1 text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                                received {{ fmtDate(iq.created_at) }}
                                                <span v-if="iq.handler_name"> · handled by {{ iq.handler_name }}</span>
                                            </div>
                                        </div>
                                        <div class="flex flex-col gap-1.5 shrink-0">
                                            <button
                                                class="btn-ghost text-xs"
                                                :disabled="inquiryBusy || iq.status === 'booked' || iq.status === 'lost'"
                                                @click="openRespondModal(iq)"
                                            >Respond</button>
                                            <button
                                                class="btn-primary text-xs"
                                                :disabled="inquiryBusy || iq.status === 'booked' || iq.status === 'lost'"
                                                @click="openBookModal(iq)"
                                            >Book</button>
                                            <button
                                                class="text-xs text-deck-soft hover:text-floor-lose disabled:opacity-40"
                                                :disabled="inquiryBusy || iq.status === 'booked' || iq.status === 'lost'"
                                                @click="markInquiryLost(iq.id)"
                                            >Mark lost</button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Booking — only when one exists -->
                        <section v-if="detail.booking" class="panel border-l-2 border-floor-win">
                            <header class="border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-floor-win">Booked ✓</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    confirmation {{ detail.booking.confirmation_number }}
                                </div>
                            </header>
                            <div class="px-4 py-3 grid grid-cols-2 gap-3 sm:grid-cols-4 text-sm">
                                <div>
                                    <div class="deck-label">Renter</div>
                                    <div class="text-deck-text">{{ detail.booking.renter_name ?? '—' }}</div>
                                    <div v-if="detail.booking.renter_email" class="text-xs text-deck-soft">{{ detail.booking.renter_email }}</div>
                                </div>
                                <div>
                                    <div class="deck-label">Dates</div>
                                    <div class="font-mono tabular-nums text-deck-text">{{ fmtDate(detail.booking.check_in_date) }}</div>
                                    <div class="font-mono tabular-nums text-deck-soft text-xs">→ {{ fmtDate(detail.booking.check_out_date) }}</div>
                                </div>
                                <div>
                                    <div class="deck-label">Total</div>
                                    <div class="deck-num text-xl">{{ fmtMoney(detail.booking.total_price) }}</div>
                                </div>
                                <div>
                                    <div class="deck-label">We earn</div>
                                    <div class="deck-num text-xl text-floor-win">{{ fmtMoney(detail.booking.our_commission) }}</div>
                                </div>
                            </div>
                            <div class="border-t border-deck-line/50 px-4 py-2 text-xs text-deck-soft flex items-center justify-between">
                                <span>Owner gets <span class="font-mono text-floor-win">{{ fmtMoney(detail.booking.owner_payout) }}</span></span>
                                <span v-if="detail.booking.owner_notified_at" class="text-floor-win">
                                    ✓ Owner notified {{ fmtDate(detail.booking.owner_notified_at) }}
                                </span>
                                <span v-else class="text-floor-lose">⚠ Owner not yet notified</span>
                            </div>
                        </section>
                    </div>

                    <!-- =========================== RIGHT THIRD =========================== -->
                    <div class="space-y-4 xl:col-span-1">
                        <!-- Agreement summary -->
                        <section class="panel p-4">
                            <h3 class="text-sm font-semibold text-deck-text mb-3">Listing agreement</h3>
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-deck-soft">Fee</dt>
                                    <dd class="deck-num">{{ fmtMoney(detail.agreement.listing_fee) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-deck-soft">Payment</dt>
                                    <dd class="font-mono text-xs uppercase tracking-wider"
                                        :class="detail.agreement.payment_status === 'paid' ? 'text-floor-win' : 'text-floor-accent'">
                                        {{ relabel(detail.agreement.payment_status) }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-deck-soft">Status</dt>
                                    <dd class="font-mono text-xs uppercase tracking-wider text-deck-text">
                                        {{ relabel(detail.agreement.agreement_status) }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-deck-soft">Signed</dt>
                                    <dd class="font-mono tabular-nums text-deck-soft text-xs">
                                        {{ fmtDate(detail.agreement.agreement_signed_at) }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <!-- Activity log -->
                        <section class="panel">
                            <header class="border-b border-deck-line px-4 py-3">
                                <div class="text-sm font-semibold text-deck-text">Activity</div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                    {{ detail.activity.length }} events
                                </div>
                            </header>
                            <ul v-if="detail.activity.length > 0" class="divide-y divide-deck-line/50">
                                <li v-for="(ev, i) in detail.activity" :key="i" class="px-4 py-2 flex items-start gap-3 text-sm">
                                    <span class="font-mono text-base shrink-0 w-5 text-center" :class="activityColor(ev.kind)">
                                        {{ activityIcon(ev.kind) }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-deck-text">{{ ev.label }}</div>
                                        <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                            {{ fmtDate(ev.occurred_at) }}
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <div v-else class="px-4 py-6 text-center text-sm text-deck-dim italic">
                                No activity yet.
                            </div>
                        </section>
                    </div>
                </div>
            </template>

            <!-- Inquiry action modal (Respond / Book) -->
            <Modal
                :open="inquiryModalOpen"
                :title="inquiryMode === 'respond' ? `Respond to ${activeInquiry?.renter_name ?? 'inquiry'}` : `Book ${activeInquiry?.renter_name ?? 'renter'}`"
                @close="inquiryModalOpen = false"
            >
                <!-- Respond -->
                <form v-if="inquiryMode === 'respond' && activeInquiry" @submit.prevent="submitRespond" class="space-y-4">
                    <div>
                        <label class="label">Message</label>
                        <textarea
                            v-model="respondMessage"
                            rows="4"
                            required
                            :placeholder="`Hi ${activeInquiry.renter_name}, thanks for reaching out…`"
                            class="input mt-1"
                        ></textarea>
                    </div>
                    <div>
                        <label class="label">Counter-offer amount (optional)</label>
                        <input
                            v-model.number="respondAmount"
                            type="number" min="0" step="0.01"
                            placeholder="Leave blank for plain response"
                            class="input mt-1"
                        />
                        <p class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mt-1">
                            Sending an amount moves the inquiry into negotiating; blank keeps it as responded.
                        </p>
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-deck-line">
                        <button type="button" class="btn-ghost text-xs" :disabled="inquiryBusy" @click="inquiryModalOpen = false">Cancel</button>
                        <button type="submit" class="btn-primary text-xs" :disabled="inquiryBusy || !respondMessage">
                            {{ inquiryBusy ? 'Sending…' : 'Send response' }}
                        </button>
                    </div>
                </form>

                <!-- Book -->
                <form v-else-if="inquiryMode === 'book' && activeInquiry" @submit.prevent="submitBook" class="space-y-4">
                    <p class="text-sm text-deck-soft">
                        Confirming this booking creates a rental, links it to this inquiry, sets the listing to <strong>booked</strong>, and dispatches owner notification.
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Check-in</label>
                            <input v-model="bookForm.check_in_date" type="date" required class="input mt-1 text-sm" />
                        </div>
                        <div>
                            <label class="label">Check-out</label>
                            <input v-model="bookForm.check_out_date" type="date" required class="input mt-1 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="label">Total rental amount (USD)</label>
                        <input v-model.number="bookForm.total_price" type="number" min="0" step="0.01" required class="input mt-1" />
                    </div>
                    <div>
                        <label class="label">Renter name</label>
                        <input v-model="bookForm.renter_name" type="text" required class="input mt-1 text-sm" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Renter email</label>
                            <input v-model="bookForm.renter_email" type="email" class="input mt-1 text-sm" />
                        </div>
                        <div>
                            <label class="label">Renter phone</label>
                            <input v-model="bookForm.renter_phone" type="tel" class="input mt-1 text-sm" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-deck-line">
                        <button type="button" class="btn-ghost text-xs" :disabled="inquiryBusy" @click="inquiryModalOpen = false">Cancel</button>
                        <button type="submit" class="btn-primary text-xs" :disabled="inquiryBusy || !bookForm.renter_name || bookForm.total_price <= 0">
                            {{ inquiryBusy ? 'Booking…' : 'Confirm booking' }}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    </AppLayout>
</template>
