<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import CreateCustomerForm from '@/Components/Customers/CreateCustomerForm.vue';

interface Customer {
    id: string;
    lead_id: string | null;
    user_id: string | null;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string;
    state: string | null;
    city: string | null;
    status: string;
    source: string | null;
    lifetime_value: string;
    total_deals: number;
    total_bookings: number;
    last_purchase_at: string | null;
    created_at: string | null;
}

interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

const customers = ref<Customer[]>([]);
const total = ref(0);
const page = ref(1);
const perPage = ref(25);
const loading = ref(false);
const search = ref('');
const status = ref('');
const sort = ref<'lifetime_value' | 'created_at' | 'last_purchase_at'>('lifetime_value');
const createOpen = ref(false);

let searchTimer: number | undefined;

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string | number> = {
            page: page.value, per_page: perPage.value,
            sort: sort.value, direction: 'desc',
        };
        if (search.value) params.q = search.value;
        if (status.value) params.status = status.value;
        const { data } = await axios.get<Paginated<Customer>>('/api/customers', { params });
        customers.value = data.data;
        total.value = data.meta.total;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { page.value = 1; void load(); }, 250);
});
watch([status, sort, page], () => void load());

function statusClass(s: string): string {
    return {
        active: 'bg-emerald-100 text-emerald-700',
        vip: 'bg-amber-100 text-amber-700',
        prospect: 'bg-blue-100 text-blue-700',
        churned: 'bg-slate-200 text-slate-600',
        blacklisted: 'bg-rose-100 text-rose-700',
    }[s] ?? 'bg-slate-100 text-slate-700';
}

const lastPage = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

function onCreated(): void {
    createOpen.value = false;
    page.value = 1;
    void load();
}

onMounted(load);
</script>

<template>
    <AppLayout title="Customers">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Customers</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ total }} total · post-conversion identities</p>
                </div>
                <button class="btn-primary" @click="createOpen = true">+ Add customer</button>
            </div>

            <Modal :open="createOpen" title="Add a customer" @close="createOpen = false">
                <CreateCustomerForm @created="onCreated" @cancel="createOpen = false" />
            </Modal>

            <section class="panel mb-4 grid grid-cols-1 gap-3 p-3 md:grid-cols-4">
                <div>
                    <label class="label">Search</label>
                    <input v-model="search" type="text" placeholder="name / email / phone" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Status</label>
                    <select v-model="status" class="input mt-1">
                        <option value="">Any</option>
                        <option value="active">Active</option>
                        <option value="vip">VIP</option>
                        <option value="prospect">Prospect</option>
                        <option value="churned">Churned</option>
                        <option value="blacklisted">Blacklisted</option>
                    </select>
                </div>
                <div>
                    <label class="label">Sort</label>
                    <select v-model="sort" class="input mt-1">
                        <option value="lifetime_value">Lifetime value</option>
                        <option value="last_purchase_at">Last purchase</option>
                        <option value="created_at">Newest</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="btn-ghost w-full text-slate-600 hover:bg-slate-100" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
                </div>
            </section>

            <div class="panel overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Name</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Phone</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Lifetime $</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Deals</th>
                            <th class="px-3 py-2 text-right text-xs font-medium uppercase text-slate-500">Bookings</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Last purchase</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!loading && customers.length === 0">
                            <td colspan="7" class="px-3 py-12 text-center text-sm text-slate-500">
                                No customers yet. Customers are auto-created when a deal closes won, or click <b>+ Add customer</b> above.
                            </td>
                        </tr>
                        <tr v-for="c in customers" :key="c.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-sm text-slate-900">
                                {{ c.full_name }}
                                <div v-if="c.email" class="text-xs text-slate-500">{{ c.email }}</div>
                                <div v-if="c.city || c.state" class="text-xs text-slate-400">{{ [c.city, c.state].filter(Boolean).join(', ') }}</div>
                            </td>
                            <td class="px-3 py-2 text-sm font-mono text-slate-700">{{ c.phone }}</td>
                            <td class="px-3 py-2"><span class="pill" :class="statusClass(c.status)">{{ c.status }}</span></td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums font-semibold text-slate-900">${{ c.lifetime_value }}</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-700">{{ c.total_deals }}</td>
                            <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-700">{{ c.total_bookings }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ c.last_purchase_at?.split('T')[0] ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="lastPage > 1" class="flex items-center justify-between px-3 py-2 text-sm text-slate-600 border-t border-slate-200 bg-slate-50">
                    <span>Page {{ page }} of {{ lastPage }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost text-slate-600 hover:bg-slate-100" :disabled="page <= 1" @click="page--">Prev</button>
                        <button class="btn-ghost text-slate-600 hover:bg-slate-100" :disabled="page >= lastPage" @click="page++">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
