<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * "Add a booking" modal body.
 *
 * Pick an existing live listing, capture renter info + final price.
 * Dates and commission auto-prefill from the listing the moment one
 * is selected; operator can override anything. Owner-payout preview
 * runs client-side using the same formula the server applies.
 */

interface ListingOption {
    id: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
    asking_price: number;
    owner_payout: number | null;
    our_commission_pct: number | null;
    property: {
        id: string;
        resort_name: string;
        resort_brand: string | null;
        location_city: string;
        location_state: string;
    };
    owner: { id: string; name: string };
}

const emit = defineEmits<{ (e: 'created', payload: { id: string }): void; (e: 'cancel'): void }>();

const form = ref({
    listing_id: '',
    renter_name: '',
    renter_email: '',
    renter_phone: '',
    check_in_date: '',
    check_out_date: '',
    total_price: null as number | null,
    commission_pct: null as number | null,
    payment_status: 'pending' as 'pending' | 'deposit_paid' | 'paid_in_full',
    notify_owner: true,
});

const listingQuery = ref('');
const listings = ref<ListingOption[]>([]);
const listingLoading = ref(false);
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

let searchTimer: number | undefined;

async function loadListings(): Promise<void> {
    listingLoading.value = true;
    try {
        const { data } = await axios.get<{ data: ListingOption[] }>(
            '/api/rental-bookings/listings-picker',
            { params: { q: listingQuery.value || undefined } },
        );
        listings.value = data.data;
    } finally {
        listingLoading.value = false;
    }
}

onMounted(loadListings);

watch(listingQuery, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void loadListings(), 250);
});

// When the listing changes, pre-fill the editable defaults — but only
// if the operator hasn't already typed something. A brand-new pick
// always wins; subsequent re-picks respect existing values.
watch(
    () => form.value.listing_id,
    (newId) => {
        const l = listings.value.find((x) => x.id === newId);
        if (!l) return;
        if (!form.value.check_in_date) form.value.check_in_date = l.check_in_date.split('T')[0];
        if (!form.value.check_out_date) form.value.check_out_date = l.check_out_date.split('T')[0];
        if (form.value.total_price == null) form.value.total_price = l.asking_price;
        if (form.value.commission_pct == null) {
            form.value.commission_pct = l.our_commission_pct ?? 15;
        }
    },
);

const selectedListing = computed<ListingOption | null>(() =>
    listings.value.find((l) => l.id === form.value.listing_id) ?? null,
);

const ownerPayoutPreview = computed<number | null>(() => {
    const price = form.value.total_price;
    const pct = form.value.commission_pct;
    if (price == null || pct == null) return null;
    return Math.round(price * (1 - pct / 100) * 100) / 100;
});

const ourCommissionPreview = computed<number | null>(() => {
    if (form.value.total_price == null || ownerPayoutPreview.value == null) return null;
    return Math.round((form.value.total_price - ownerPayoutPreview.value) * 100) / 100;
});

function fmtMoney(n: number | null): string {
    if (n == null) return '—';
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload: Record<string, unknown> = { ...form.value };
    for (const k of Object.keys(payload)) {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    }
    // `notify_owner` is a boolean and must survive even when false.
    payload.notify_owner = form.value.notify_owner;

    try {
        const { data } = await axios.post('/api/rental-bookings', payload);
        emit('created', { id: data.data.id });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length && e.response?.data?.message) {
            errors.value._global = [e.response.data.message];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errors._global[0] }}
        </div>

        <!-- Listing picker -->
        <div>
            <label class="label">Listing <span class="text-red-600">*</span></label>
            <input
                v-model="listingQuery"
                type="text"
                placeholder="search owner or resort"
                class="input mt-1 text-sm"
            />
            <div class="mt-2 max-h-48 overflow-y-auto rounded border border-slate-200 bg-white">
                <div v-if="listingLoading" class="px-3 py-2 text-xs text-slate-500">Loading…</div>
                <div v-else-if="listings.length === 0" class="px-3 py-2 text-xs italic text-slate-500">
                    No live listings match. Bookings require an active listing — create one from the Listings page first.
                </div>
                <label
                    v-for="l in listings"
                    :key="l.id"
                    class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    :class="form.listing_id === l.id ? 'bg-amber-50' : ''"
                >
                    <input
                        v-model="form.listing_id"
                        type="radio"
                        :value="l.id"
                        class="mt-1"
                    />
                    <div class="flex-1">
                        <div class="font-medium text-slate-900">
                            {{ l.property.resort_name }}
                            <span class="text-xs font-normal uppercase tracking-wider text-slate-500">· {{ l.status.replace('_', ' ') }}</span>
                        </div>
                        <div class="text-xs text-slate-600">
                            <span v-if="l.property.resort_brand">{{ l.property.resort_brand }} · </span>
                            {{ l.property.location_city }}, {{ l.property.location_state }}
                            · {{ l.check_in_date.split('T')[0] }} → {{ l.check_out_date.split('T')[0] }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Owner: {{ l.owner.name }} · asking {{ fmtMoney(l.asking_price) }}
                        </div>
                    </div>
                </label>
            </div>
            <p v-if="errors.listing_id" class="mt-1 text-xs text-red-600">{{ errors.listing_id[0] }}</p>
        </div>

        <!-- Renter -->
        <fieldset class="grid grid-cols-2 gap-3">
            <div class="col-span-2">
                <label class="label">Renter name <span class="text-red-600">*</span></label>
                <input v-model="form.renter_name" type="text" class="input mt-1" maxlength="200" required />
                <p v-if="errors.renter_name" class="mt-1 text-xs text-red-600">{{ errors.renter_name[0] }}</p>
            </div>
            <div>
                <label class="label">Renter email</label>
                <input v-model="form.renter_email" type="email" class="input mt-1" maxlength="200" />
                <p v-if="errors.renter_email" class="mt-1 text-xs text-red-600">{{ errors.renter_email[0] }}</p>
            </div>
            <div>
                <label class="label">Renter phone</label>
                <input v-model="form.renter_phone" type="tel" class="input mt-1" maxlength="30" />
                <p v-if="errors.renter_phone" class="mt-1 text-xs text-red-600">{{ errors.renter_phone[0] }}</p>
            </div>
        </fieldset>

        <!-- Dates -->
        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">Check-in</label>
                <input v-model="form.check_in_date" type="date" class="input mt-1" />
                <p v-if="errors.check_in_date" class="mt-1 text-xs text-red-600">{{ errors.check_in_date[0] }}</p>
            </div>
            <div>
                <label class="label">Check-out</label>
                <input v-model="form.check_out_date" type="date" class="input mt-1" />
                <p v-if="errors.check_out_date" class="mt-1 text-xs text-red-600">{{ errors.check_out_date[0] }}</p>
            </div>
        </fieldset>

        <!-- Pricing -->
        <fieldset class="grid grid-cols-3 gap-3">
            <div>
                <label class="label">Total price ($) <span class="text-red-600">*</span></label>
                <input v-model.number="form.total_price" type="number" min="0" step="50" class="input mt-1" required />
                <p v-if="errors.total_price" class="mt-1 text-xs text-red-600">{{ errors.total_price[0] }}</p>
            </div>
            <div>
                <label class="label">Commission %</label>
                <input v-model.number="form.commission_pct" type="number" min="0" max="100" step="0.5" class="input mt-1" />
                <p v-if="errors.commission_pct" class="mt-1 text-xs text-red-600">{{ errors.commission_pct[0] }}</p>
            </div>
            <div>
                <label class="label">Payment</label>
                <select v-model="form.payment_status" class="input mt-1">
                    <option value="pending">Pending</option>
                    <option value="deposit_paid">Deposit paid</option>
                    <option value="paid_in_full">Paid in full</option>
                </select>
            </div>
        </fieldset>

        <div v-if="ownerPayoutPreview !== null" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            Owner gets <span class="font-semibold text-slate-900">{{ fmtMoney(ownerPayoutPreview) }}</span>
            · we earn <span class="font-semibold text-slate-900">{{ fmtMoney(ourCommissionPreview) }}</span>
        </div>

        <!-- Owner notification -->
        <div class="flex items-start gap-2">
            <input id="notify_owner" v-model="form.notify_owner" type="checkbox" class="mt-1" />
            <label for="notify_owner" class="text-sm text-slate-700">
                Notify owner
                <span class="block text-xs text-slate-500">
                    Drops a system note on the owner's profile. Uncheck only if you've already told them out-of-band.
                </span>
            </label>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting || !form.listing_id">
                {{ submitting ? 'Saving…' : 'Confirm booking' }}
            </button>
        </div>
    </form>
</template>
