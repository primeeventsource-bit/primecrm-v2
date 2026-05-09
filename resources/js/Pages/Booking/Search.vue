<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Availability {
    id: string;
    resort_id: string;
    check_in_date: string;
    check_out_date: string;
    nights: number;
    status: string;
    current_price: string;
    currency: string;
    unit?: { unit_type: string; sleeps: number };
    resort?: { name: string; brand: string; city: string; state: string };
}

const checkInFrom = ref(new Date().toISOString().split('T')[0]);
const checkInTo = ref(new Date(Date.now() + 90 * 86_400_000).toISOString().split('T')[0]);
const brand = ref('');
const unitType = ref('');
const sleepsMin = ref<number | null>(null);
const results = ref<Availability[]>([]);
const loading = ref(false);
const holdingId = ref<string | null>(null);

async function search(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string | number> = {
            check_in_from: checkInFrom.value,
            check_in_to: checkInTo.value,
        };
        if (brand.value) params.brand = brand.value;
        if (unitType.value) params.unit_type = unitType.value;
        if (sleepsMin.value) params.sleeps_min = sleepsMin.value;

        const { data } = await axios.get<{ data: Availability[] }>('/api/inventory/search', { params });
        results.value = data.data;
    } finally {
        loading.value = false;
    }
}

async function hold(a: Availability): Promise<void> {
    holdingId.value = a.id;
    try {
        const { data } = await axios.post<{ data: { id: string; expires_at: string } }>(
            '/api/inventory/holds',
            { inventory_availability_id: a.id },
        );
        alert(`Held until ${new Date(data.data.expires_at).toLocaleString()}. Hold id ${data.data.id}.`);
        await search();
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        alert(err.response?.data?.message ?? 'Failed to hold unit.');
    } finally {
        holdingId.value = null;
    }
}

onMounted(search);
</script>

<template>
    <AppLayout title="Inventory Search">
        <div class="p-6">
            <section class="panel mb-4 grid grid-cols-2 gap-3 p-4 lg:grid-cols-5">
                <div>
                    <label class="label">Check-in from</label>
                    <input v-model="checkInFrom" type="date" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Check-in to</label>
                    <input v-model="checkInTo" type="date" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Brand</label>
                    <input v-model="brand" type="text" placeholder="Westgate" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Unit type</label>
                    <select v-model="unitType" class="input mt-1">
                        <option value="">Any</option>
                        <option value="studio">Studio</option>
                        <option value="1br">1BR</option>
                        <option value="2br">2BR</option>
                        <option value="3br">3BR</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="btn-primary w-full" :disabled="loading" @click="search">
                        {{ loading ? 'Searching…' : 'Search' }}
                    </button>
                </div>
            </section>

            <div class="space-y-2">
                <div v-if="results.length === 0 && !loading" class="panel p-4 text-center text-sm text-slate-500">
                    No matches.
                </div>
                <article
                    v-for="r in results"
                    :key="r.id"
                    class="panel flex items-center justify-between p-4"
                >
                    <div>
                        <div class="font-medium text-slate-900">
                            {{ r.resort?.name ?? '(unknown)' }} · {{ r.unit?.unit_type ?? '' }}
                        </div>
                        <div class="text-sm text-slate-500">
                            {{ r.resort?.city }}, {{ r.resort?.state }} · sleeps {{ r.unit?.sleeps ?? '—' }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ r.check_in_date }} → {{ r.check_out_date }} ({{ r.nights }} nights)
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-semibold text-slate-900">${{ r.current_price }}</div>
                        <button
                            class="btn-success mt-1"
                            :disabled="holdingId === r.id"
                            @click="hold(r)"
                        >
                            {{ holdingId === r.id ? 'Holding…' : 'Hold' }}
                        </button>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
