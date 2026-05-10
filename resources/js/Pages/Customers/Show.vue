<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NotesPanel from '@/Components/NotesPanel.vue';
import type { PageProps } from '@/types/api';

interface Customer {
    id: string;
    lead_id: string | null;
    user_id: string | null;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    country: string | null;
    state: string | null;
    city: string | null;
    postal_code: string | null;
    timezone: string | null;
    status: string;
    source: string | null;
    lifetime_value: string;
    total_deals: number;
    total_bookings: number;
    first_purchase_at: string | null;
    last_purchase_at: string | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
}

interface Deal {
    id: string;
    stage: string;
    total_value: string;
    payable_amount: string;
    closed_at: string | null;
    created_at: string | null;
}

const props = defineProps<{ customerId: string }>();

const page = usePage<PageProps>();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);

const customer = ref<Customer | null>(null);
const deals = ref<Deal[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<{ data: Customer }>(`/api/customers/${props.customerId}`);
        customer.value = data.data;
        if (customer.value?.lead_id) {
            await loadDeals(customer.value.lead_id);
        }
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load this customer.';
    } finally {
        loading.value = false;
    }
}

async function loadDeals(leadId: string): Promise<void> {
    try {
        const { data } = await axios.get<{ data: Deal[] }>('/api/deals', {
            params: { lead_id: leadId, per_page: 50 },
        });
        deals.value = data.data;
    } catch {
        deals.value = [];
    }
}

function statusClass(s: string): string {
    return {
        active: 'bg-emerald-100 text-emerald-700',
        vip: 'bg-amber-100 text-amber-700',
        prospect: 'bg-blue-100 text-blue-700',
        churned: 'bg-slate-200 text-slate-600',
        blacklisted: 'bg-rose-100 text-rose-700',
    }[s] ?? 'bg-slate-100 text-slate-700';
}

function stageBadge(stage: string): string {
    if (stage === 'closed_won') return 'bg-emerald-100 text-emerald-700';
    if (stage === 'closed_lost') return 'bg-rose-100 text-rose-700';
    return 'bg-slate-100 text-slate-700';
}

function fmt(v: string | null | undefined): string {
    return v && v !== '' ? v : '—';
}

onMounted(load);
</script>

<template>
    <AppLayout :title="customer ? `Customer · ${customer.full_name}` : 'Customer'">
        <div class="p-6">
            <div class="mb-4">
                <Link href="/customers" class="text-xs text-deck-soft hover:text-deck-text">← Back to customers</Link>
            </div>

            <div v-if="loading" class="panel p-6 text-sm text-deck-soft">Loading…</div>
            <div v-else-if="error" class="panel p-6 text-sm text-rose-600">{{ error }}</div>

            <template v-else-if="customer">
                <!-- Header -->
                <div class="panel mb-4 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-semibold text-deck-text">{{ customer.full_name }}</h2>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-deck-soft">
                                <span class="font-mono">{{ customer.phone }}</span>
                                <span v-if="customer.email">· {{ customer.email }}</span>
                                <span v-if="customer.city || customer.state">
                                    · {{ [customer.city, customer.state].filter(Boolean).join(', ') }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="pill" :class="statusClass(customer.status)">{{ customer.status }}</span>
                                <span class="pill bg-slate-100 text-slate-700">source: {{ customer.source ?? '—' }}</span>
                            </div>
                        </div>
                        <div v-if="customer.lead_id" class="text-right">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">Originating lead</div>
                            <Link :href="`/leads/${customer.lead_id}`" class="text-sm font-semibold text-floor-accent hover:underline">
                                view lead →
                            </Link>
                        </div>
                    </div>

                    <!-- Metric strip -->
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-md border border-deck-line bg-deck-raised p-3">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">Lifetime value</div>
                            <div class="mt-1 text-xl font-mono tabular-nums font-semibold text-deck-text">${{ customer.lifetime_value }}</div>
                        </div>
                        <div class="rounded-md border border-deck-line bg-deck-raised p-3">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">Deals</div>
                            <div class="mt-1 text-xl font-mono tabular-nums font-semibold text-deck-text">{{ customer.total_deals }}</div>
                        </div>
                        <div class="rounded-md border border-deck-line bg-deck-raised p-3">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">Bookings</div>
                            <div class="mt-1 text-xl font-mono tabular-nums font-semibold text-deck-text">{{ customer.total_bookings }}</div>
                        </div>
                        <div class="rounded-md border border-deck-line bg-deck-raised p-3">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">First purchase</div>
                            <div class="mt-1 text-sm text-deck-text">{{ customer.first_purchase_at?.split('T')[0] ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <!-- Profile facts -->
                    <section class="panel p-4 lg:col-span-1">
                        <h3 class="mb-3 text-sm font-semibold text-deck-text">Profile</h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Alt phone</dt><dd class="text-deck-text font-mono">{{ fmt(customer.alternate_phone) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Postal</dt><dd class="text-deck-text">{{ fmt(customer.postal_code) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Country</dt><dd class="text-deck-text">{{ fmt(customer.country) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Timezone</dt><dd class="text-deck-text">{{ fmt(customer.timezone) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Last purchase</dt><dd class="text-deck-text">{{ customer.last_purchase_at?.split('T')[0] ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Created</dt><dd class="text-deck-text">{{ customer.created_at?.split('T')[0] ?? '—' }}</dd></div>
                        </dl>

                        <div v-if="deals.length" class="mt-5">
                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-deck-soft">Deals</h4>
                            <ul class="space-y-1.5 text-sm">
                                <li v-for="d in deals" :key="d.id" class="flex items-center justify-between gap-2">
                                    <span class="pill" :class="stageBadge(d.stage)">{{ d.stage.replace(/_/g, ' ') }}</span>
                                    <span class="font-mono tabular-nums text-deck-text">${{ d.total_value }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- Notes timeline -->
                    <div class="lg:col-span-2">
                        <NotesPanel notable-type="customer" :notable-id="customer.id" :current-user-id="currentUserId" />
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
