<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * "Add a listing" modal body.
 *
 * Pick an existing tenant property, set the rental window and price,
 * optionally override commission %. Owner payout is computed on the
 * fly so the operator sees the owner-facing number live; the server
 * recomputes from the same inputs so client-side math is advisory.
 */

interface PropertyOption {
    id: string;
    resort_name: string;
    resort_brand: string | null;
    location_city: string;
    location_state: string;
    unit_number: string | null;
    bedrooms: number | null;
    sleeps: number | null;
    ownership_verified: boolean;
    rental_allowed_by_resort: boolean;
    owner: { id: string; name: string };
}

const emit = defineEmits<{ (e: 'created', payload: { id: string }): void; (e: 'cancel'): void }>();

const form = ref({
    property_id: '',
    check_in_date: '',
    check_out_date: '',
    asking_price: null as number | null,
    reserve_price: null as number | null,
    our_commission_pct: 15 as number | null,
    marketing_description: '',
    go_live: false,
});

const propertyQuery = ref('');
const properties = ref<PropertyOption[]>([]);
const propertyLoading = ref(false);
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

let searchTimer: number | undefined;

async function loadProperties(): Promise<void> {
    propertyLoading.value = true;
    try {
        const { data } = await axios.get<{ data: PropertyOption[] }>(
            '/api/listings/properties-picker',
            { params: { q: propertyQuery.value || undefined } },
        );
        properties.value = data.data;
    } finally {
        propertyLoading.value = false;
    }
}

onMounted(loadProperties);

watch(propertyQuery, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void loadProperties(), 250);
});

const selectedProperty = computed<PropertyOption | null>(() =>
    properties.value.find((p) => p.id === form.value.property_id) ?? null,
);

const ownerPayoutPreview = computed<number | null>(() => {
    const price = form.value.asking_price;
    const pct = form.value.our_commission_pct;
    if (price == null || pct == null) return null;
    return Math.round(price * (1 - pct / 100) * 100) / 100;
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

    try {
        const { data } = await axios.post('/api/listings', payload);
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

        <!-- Property picker -->
        <div>
            <label class="label">Property <span class="text-red-600">*</span></label>
            <input
                v-model="propertyQuery"
                type="text"
                placeholder="search owner or resort"
                class="input mt-1 text-sm"
            />
            <div class="mt-2 max-h-48 overflow-y-auto rounded border border-slate-200 bg-white">
                <div v-if="propertyLoading" class="px-3 py-2 text-xs text-slate-500">Loading…</div>
                <div v-else-if="properties.length === 0" class="px-3 py-2 text-xs italic text-slate-500">
                    No properties match. Properties are created elsewhere — verify owner is on file first.
                </div>
                <label
                    v-for="p in properties"
                    :key="p.id"
                    class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    :class="form.property_id === p.id ? 'bg-amber-50' : ''"
                >
                    <input
                        v-model="form.property_id"
                        type="radio"
                        :value="p.id"
                        class="mt-1"
                    />
                    <div class="flex-1">
                        <div class="font-medium text-slate-900">
                            {{ p.resort_name }}
                            <span v-if="p.unit_number" class="text-xs text-slate-500">· #{{ p.unit_number }}</span>
                        </div>
                        <div class="text-xs text-slate-600">
                            <span v-if="p.resort_brand">{{ p.resort_brand }} · </span>
                            {{ p.location_city }}, {{ p.location_state }}
                            <span v-if="p.bedrooms !== null"> · {{ p.bedrooms }}br / sleeps {{ p.sleeps ?? '?' }}</span>
                        </div>
                        <div class="text-xs text-slate-500">Owner: {{ p.owner.name }}</div>
                        <div v-if="!p.ownership_verified || !p.rental_allowed_by_resort" class="mt-0.5 text-[10px] uppercase tracking-wider text-amber-700">
                            <span v-if="!p.ownership_verified">unverified</span>
                            <span v-if="!p.ownership_verified && !p.rental_allowed_by_resort"> · </span>
                            <span v-if="!p.rental_allowed_by_resort">rental not confirmed</span>
                        </div>
                    </div>
                </label>
            </div>
            <p v-if="errors.property_id" class="mt-1 text-xs text-red-600">{{ errors.property_id[0] }}</p>
        </div>

        <!-- Rental window -->
        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">Check-in <span class="text-red-600">*</span></label>
                <input v-model="form.check_in_date" type="date" class="input mt-1" required />
                <p v-if="errors.check_in_date" class="mt-1 text-xs text-red-600">{{ errors.check_in_date[0] }}</p>
            </div>
            <div>
                <label class="label">Check-out <span class="text-red-600">*</span></label>
                <input v-model="form.check_out_date" type="date" class="input mt-1" required />
                <p v-if="errors.check_out_date" class="mt-1 text-xs text-red-600">{{ errors.check_out_date[0] }}</p>
            </div>
        </fieldset>

        <!-- Pricing -->
        <fieldset class="grid grid-cols-3 gap-3">
            <div>
                <label class="label">Asking price ($) <span class="text-red-600">*</span></label>
                <input v-model.number="form.asking_price" type="number" min="0" step="50" class="input mt-1" required />
                <p v-if="errors.asking_price" class="mt-1 text-xs text-red-600">{{ errors.asking_price[0] }}</p>
            </div>
            <div>
                <label class="label">Reserve ($)</label>
                <input v-model.number="form.reserve_price" type="number" min="0" step="50" class="input mt-1" />
                <p v-if="errors.reserve_price" class="mt-1 text-xs text-red-600">{{ errors.reserve_price[0] }}</p>
            </div>
            <div>
                <label class="label">Commission %</label>
                <input v-model.number="form.our_commission_pct" type="number" min="0" max="100" step="0.5" class="input mt-1" />
                <p v-if="errors.our_commission_pct" class="mt-1 text-xs text-red-600">{{ errors.our_commission_pct[0] }}</p>
            </div>
        </fieldset>

        <div v-if="ownerPayoutPreview !== null" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            Owner gets <span class="font-semibold text-slate-900">{{ fmtMoney(ownerPayoutPreview) }}</span>
            <span class="text-xs text-slate-500">
                · we earn {{ fmtMoney(form.asking_price !== null ? form.asking_price - (ownerPayoutPreview ?? 0) : null) }}
            </span>
        </div>

        <!-- Marketing -->
        <div>
            <label class="label">Marketing description</label>
            <textarea
                v-model="form.marketing_description"
                rows="3"
                maxlength="5000"
                placeholder="What renters see on partner sites. Leave blank to fill in later."
                class="input mt-1 text-sm"
            />
            <p v-if="errors.marketing_description" class="mt-1 text-xs text-red-600">{{ errors.marketing_description[0] }}</p>
        </div>

        <!-- Initial status -->
        <div class="flex items-center gap-2">
            <input id="go_live" v-model="form.go_live" type="checkbox" />
            <label for="go_live" class="text-sm text-slate-700">
                Mark live immediately
                <span class="text-xs text-slate-500">(skip pending-distribution; useful if it's already on partner sites)</span>
            </label>
        </div>

        <!-- Selected-property warnings -->
        <div
            v-if="selectedProperty && (!selectedProperty.ownership_verified || !selectedProperty.rental_allowed_by_resort)"
            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
        >
            Heads up: this property is missing
            <span v-if="!selectedProperty.ownership_verified">ownership verification</span>
            <span v-if="!selectedProperty.ownership_verified && !selectedProperty.rental_allowed_by_resort"> and </span>
            <span v-if="!selectedProperty.rental_allowed_by_resort">resort rental confirmation</span>.
            The listing will save, but partner sites typically reject unverified rows.
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting || !form.property_id">
                {{ submitting ? 'Saving…' : 'Create listing' }}
            </button>
        </div>
    </form>
</template>
