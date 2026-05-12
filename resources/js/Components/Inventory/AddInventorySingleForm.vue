<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * "Add one inventory row" — singular form body for the modal.
 *
 * Resort and unit are both pick-or-create: the operator can type to
 * search existing tenant resorts, or hit "New resort" to fill in
 * resort fields inline. Same pattern for unit. The server enforces
 * required_without on the matching new-entity fields.
 */

interface ResortOption {
    id: string;
    name: string;
    brand: string | null;
    city: string;
    state: string;
    country: string;
    timezone: string;
}

interface UnitOption {
    id: string;
    unit_type: string;
    sleeps: number;
    features: string[];
}

const emit = defineEmits<{ (e: 'created'): void; (e: 'cancel'): void }>();

const mode = ref<{ resort: 'pick' | 'new'; unit: 'pick' | 'new' }>({
    resort: 'pick',
    unit: 'pick',
});

const form = ref({
    resort_id: '',
    resort_new: {
        name: '',
        brand: '',
        city: '',
        state: '',
        country: 'US',
        timezone: 'America/New_York',
    },
    unit_id: '',
    unit_new: {
        unit_type: '2br',
        sleeps: 6 as number | null,
        features_csv: '',
    },
    check_in_date: '',
    check_out_date: '',
    base_price: null as number | null,
    currency: 'USD',
});

const resortQuery = ref('');
const resorts = ref<ResortOption[]>([]);
const resortLoading = ref(false);

const units = ref<UnitOption[]>([]);
const unitLoading = ref(false);

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const successMsg = ref<string | null>(null);

let resortTimer: number | undefined;

async function loadResorts(): Promise<void> {
    resortLoading.value = true;
    try {
        const { data } = await axios.get<{ data: ResortOption[] }>(
            '/api/inventory/resorts-picker',
            { params: { q: resortQuery.value || undefined } },
        );
        resorts.value = data.data;
    } finally {
        resortLoading.value = false;
    }
}

async function loadUnits(resortId: string): Promise<void> {
    if (!resortId) {
        units.value = [];
        return;
    }
    unitLoading.value = true;
    try {
        const { data } = await axios.get<{ data: UnitOption[] }>(
            '/api/inventory/units-picker',
            { params: { resort_id: resortId } },
        );
        units.value = data.data;
    } finally {
        unitLoading.value = false;
    }
}

onMounted(loadResorts);

watch(resortQuery, () => {
    if (resortTimer !== undefined) clearTimeout(resortTimer);
    resortTimer = window.setTimeout(() => void loadResorts(), 250);
});

watch(
    () => form.value.resort_id,
    (id) => {
        form.value.unit_id = '';
        if (id) void loadUnits(id);
        else units.value = [];
    },
);

const selectedResort = computed<ResortOption | null>(() =>
    resorts.value.find((r) => r.id === form.value.resort_id) ?? null,
);

const nightsPreview = computed<number | null>(() => {
    const a = form.value.check_in_date;
    const b = form.value.check_out_date;
    if (!a || !b) return null;
    const da = new Date(a).getTime();
    const db = new Date(b).getTime();
    if (Number.isNaN(da) || Number.isNaN(db) || db <= da) return null;
    return Math.round((db - da) / 86400000);
});

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    successMsg.value = null;

    const payload: Record<string, unknown> = {
        check_in_date: form.value.check_in_date,
        check_out_date: form.value.check_out_date,
        base_price: form.value.base_price,
        currency: form.value.currency,
    };

    if (mode.value.resort === 'pick') {
        payload.resort_id = form.value.resort_id;
    } else {
        payload.resort_new = { ...form.value.resort_new };
    }

    if (mode.value.unit === 'pick') {
        payload.unit_id = form.value.unit_id;
    } else {
        payload.unit_new = {
            unit_type: form.value.unit_new.unit_type,
            sleeps: form.value.unit_new.sleeps,
            features: form.value.unit_new.features_csv
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
        };
    }

    try {
        const { data } = await axios.post('/api/inventory/availability', payload);
        successMsg.value = `Created ${data.data.unit.unit_type} at ${data.data.resort.name}.`;
        emit('created');
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } };
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
        <div v-if="successMsg" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {{ successMsg }}
        </div>

        <!-- Resort: pick existing or create new -->
        <section>
            <div class="mb-1 flex items-center justify-between">
                <label class="label">Resort <span class="text-red-600">*</span></label>
                <div class="text-xs">
                    <button
                        type="button"
                        class="text-floor-accent hover:underline"
                        @click="mode.resort = mode.resort === 'pick' ? 'new' : 'pick'"
                    >
                        {{ mode.resort === 'pick' ? '+ New resort' : '← Pick existing' }}
                    </button>
                </div>
            </div>

            <div v-if="mode.resort === 'pick'">
                <input
                    v-model="resortQuery"
                    type="text"
                    placeholder="search resort name, brand, or city"
                    class="input text-sm"
                />
                <div class="mt-2 max-h-40 overflow-y-auto rounded border border-slate-200 bg-white">
                    <div v-if="resortLoading" class="px-3 py-2 text-xs text-slate-500">Loading…</div>
                    <div v-else-if="resorts.length === 0" class="px-3 py-2 text-xs italic text-slate-500">
                        No resorts match. Switch to "+ New resort" to create one.
                    </div>
                    <label
                        v-for="r in resorts"
                        :key="r.id"
                        class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                        :class="form.resort_id === r.id ? 'bg-amber-50' : ''"
                    >
                        <input v-model="form.resort_id" type="radio" :value="r.id" class="mt-1" />
                        <div class="flex-1">
                            <div class="font-medium text-slate-900">
                                {{ r.name }}
                                <span v-if="r.brand" class="text-xs font-normal text-slate-500">· {{ r.brand }}</span>
                            </div>
                            <div class="text-xs text-slate-600">{{ r.city }}, {{ r.state }}</div>
                        </div>
                    </label>
                </div>
                <p v-if="errors.resort_id" class="mt-1 text-xs text-red-600">{{ errors.resort_id[0] }}</p>
            </div>

            <div v-else class="grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="col-span-2">
                    <label class="label">Resort name <span class="text-red-600">*</span></label>
                    <input v-model="form.resort_new.name" type="text" class="input mt-1" maxlength="200" />
                    <p v-if="errors['resort_new.name']" class="mt-1 text-xs text-red-600">{{ errors['resort_new.name'][0] }}</p>
                </div>
                <div>
                    <label class="label">Brand</label>
                    <input v-model="form.resort_new.brand" type="text" placeholder="Marriott / Hilton / …" class="input mt-1" maxlength="120" />
                </div>
                <div>
                    <label class="label">Country</label>
                    <input v-model="form.resort_new.country" type="text" maxlength="2" class="input mt-1 uppercase" />
                </div>
                <div>
                    <label class="label">City <span class="text-red-600">*</span></label>
                    <input v-model="form.resort_new.city" type="text" class="input mt-1" maxlength="120" />
                    <p v-if="errors['resort_new.city']" class="mt-1 text-xs text-red-600">{{ errors['resort_new.city'][0] }}</p>
                </div>
                <div>
                    <label class="label">State <span class="text-red-600">*</span></label>
                    <input v-model="form.resort_new.state" type="text" maxlength="2" class="input mt-1 uppercase" />
                    <p v-if="errors['resort_new.state']" class="mt-1 text-xs text-red-600">{{ errors['resort_new.state'][0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="label">Timezone</label>
                    <input v-model="form.resort_new.timezone" type="text" placeholder="America/New_York" class="input mt-1" maxlength="64" />
                </div>
            </div>
        </section>

        <!-- Unit: pick existing or create new (only when resort is set) -->
        <section v-if="mode.resort === 'pick' && !form.resort_id" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
            Pick a resort first, then choose a unit.
        </section>
        <section v-else>
            <div class="mb-1 flex items-center justify-between">
                <label class="label">Unit <span class="text-red-600">*</span></label>
                <div class="text-xs">
                    <button
                        type="button"
                        class="text-floor-accent hover:underline"
                        @click="mode.unit = mode.unit === 'pick' ? 'new' : 'pick'"
                    >
                        {{ mode.unit === 'pick' ? '+ New unit' : '← Pick existing' }}
                    </button>
                </div>
            </div>

            <div v-if="mode.unit === 'pick'">
                <div v-if="unitLoading" class="rounded border border-slate-200 px-3 py-2 text-xs text-slate-500">Loading units…</div>
                <div v-else-if="units.length === 0" class="rounded border border-slate-200 px-3 py-2 text-xs italic text-slate-500">
                    No units for this resort yet. Switch to "+ New unit" to create one.
                </div>
                <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <label
                        v-for="u in units"
                        :key="u.id"
                        class="flex cursor-pointer items-center gap-2 rounded border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50"
                        :class="form.unit_id === u.id ? 'bg-amber-50 ring-1 ring-amber-300' : ''"
                    >
                        <input v-model="form.unit_id" type="radio" :value="u.id" />
                        <span class="font-medium">{{ u.unit_type }}</span>
                        <span class="text-xs text-slate-500">sleeps {{ u.sleeps }}</span>
                    </label>
                </div>
                <p v-if="errors.unit_id" class="mt-1 text-xs text-red-600">{{ errors.unit_id[0] }}</p>
            </div>

            <div v-else class="grid grid-cols-3 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                <div>
                    <label class="label">Type <span class="text-red-600">*</span></label>
                    <select v-model="form.unit_new.unit_type" class="input mt-1">
                        <option value="studio">Studio</option>
                        <option value="1br">1 bedroom</option>
                        <option value="2br">2 bedroom</option>
                        <option value="3br">3 bedroom</option>
                        <option value="presidential">Presidential</option>
                    </select>
                </div>
                <div>
                    <label class="label">Sleeps <span class="text-red-600">*</span></label>
                    <input v-model.number="form.unit_new.sleeps" type="number" min="1" max="20" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Features</label>
                    <input v-model="form.unit_new.features_csv" type="text" placeholder="ocean_view, balcony" class="input mt-1" />
                </div>
            </div>
        </section>

        <!-- Availability window -->
        <section class="grid grid-cols-2 gap-3">
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
        </section>

        <section class="grid grid-cols-3 gap-3">
            <div class="col-span-2">
                <label class="label">Base price ($) <span class="text-red-600">*</span></label>
                <input v-model.number="form.base_price" type="number" min="0" step="50" class="input mt-1" required />
                <p v-if="errors.base_price" class="mt-1 text-xs text-red-600">{{ errors.base_price[0] }}</p>
            </div>
            <div>
                <label class="label">Currency</label>
                <input v-model="form.currency" type="text" maxlength="3" class="input mt-1 uppercase" />
            </div>
        </section>

        <div v-if="nightsPreview !== null" class="text-xs text-slate-600">
            {{ nightsPreview }}-night window.
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? 'Saving…' : 'Add inventory' }}
            </button>
        </div>
    </form>
</template>
