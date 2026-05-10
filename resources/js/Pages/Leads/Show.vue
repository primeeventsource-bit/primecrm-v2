<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NotesPanel from '@/Components/NotesPanel.vue';
import ConvertLeadPanel from '@/Components/Leads/ConvertLeadPanel.vue';
import type { PageProps, Lead } from '@/types/api';

interface Customer {
    id: string;
    full_name: string;
    email: string | null;
    phone: string;
    status: string;
    lifetime_value: string;
    total_deals: number;
}

const props = defineProps<{ leadId: string }>();

const page = usePage<PageProps>();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);

const lead = ref<Lead | null>(null);
const customer = ref<Customer | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function loadLead(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<{ data: Lead }>(`/api/leads/${props.leadId}`);
        lead.value = data.data;
        await loadLinkedCustomer();
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load this lead.';
    } finally {
        loading.value = false;
    }
}

/**
 * Customers carry `lead_id` back to their originating lead. Filter the
 * customers index by that to surface the conversion link without an
 * extra dedicated endpoint.
 */
async function loadLinkedCustomer(): Promise<void> {
    try {
        const { data } = await axios.get<{ data: Customer[] }>('/api/customers', {
            params: { lead_id: props.leadId, per_page: 1 },
        });
        customer.value = data.data[0] ?? null;
    } catch {
        customer.value = null;
    }
}

function priorityClass(p: string | null | undefined): string {
    return {
        hot: 'bg-rose-100 text-rose-700',
        high: 'bg-amber-100 text-amber-700',
        normal: 'bg-slate-100 text-slate-700',
        low: 'bg-slate-100 text-slate-500',
    }[p ?? 'normal'] ?? 'bg-slate-100 text-slate-700';
}

function statusClass(s: string | null | undefined): string {
    if (!s) return 'bg-slate-100 text-slate-700';
    if (s === 'closed_won') return 'bg-emerald-100 text-emerald-700';
    if (s === 'closed_lost' || s === 'dnc' || s === 'do_not_contact') return 'bg-rose-100 text-rose-700';
    if (s === 'qualified' || s === 'pitch_presented' || s === 'negotiating') return 'bg-blue-100 text-blue-700';
    return 'bg-slate-100 text-slate-700';
}

function fmt(v: string | null | undefined): string {
    return v && v !== '' ? v : '—';
}

onMounted(loadLead);
</script>

<template>
    <AppLayout :title="lead ? `Lead · ${lead.full_name || lead.phone}` : 'Lead'">
        <div class="p-6">
            <div class="mb-4">
                <Link href="/leads" class="text-xs text-deck-soft hover:text-deck-text">← Back to leads</Link>
            </div>

            <div v-if="loading" class="panel p-6 text-sm text-deck-soft">Loading…</div>
            <div v-else-if="error" class="panel p-6 text-sm text-rose-600">{{ error }}</div>

            <template v-else-if="lead">
                <!-- Header -->
                <div class="panel mb-4 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-semibold text-deck-text">
                                {{ lead.full_name || '(unnamed lead)' }}
                            </h2>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-deck-soft">
                                <span class="font-mono">{{ lead.phone }}</span>
                                <span v-if="lead.email">· {{ lead.email }}</span>
                                <span v-if="lead.city || lead.state">
                                    · {{ [lead.city, lead.state].filter(Boolean).join(', ') }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="pill" :class="statusClass(lead.status)">{{ (lead.status ?? 'new').replace(/_/g, ' ') }}</span>
                                <span class="pill" :class="priorityClass(lead.priority)">{{ lead.priority ?? 'normal' }}</span>
                                <span class="pill bg-slate-100 text-slate-700">score {{ lead.score }}</span>
                                <span v-if="lead.is_on_dnc" class="pill bg-rose-100 text-rose-700">DNC</span>
                                <span v-if="lead.has_express_consent" class="pill bg-emerald-100 text-emerald-700">consent</span>
                            </div>
                        </div>
                        <div v-if="customer" class="text-right">
                            <div class="text-[10px] uppercase tracking-wider text-deck-dim">Converted to customer</div>
                            <Link :href="`/customers/${customer.id}`" class="text-sm font-semibold text-floor-accent hover:underline">
                                {{ customer.full_name }} →
                            </Link>
                            <div class="mt-0.5 text-xs text-deck-soft">${{ customer.lifetime_value }} LTV · {{ customer.total_deals }} deals</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <!-- Profile facts -->
                    <section class="panel p-4 lg:col-span-1">
                        <h3 class="mb-3 text-sm font-semibold text-deck-text">Profile</h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Source</dt><dd class="text-deck-text">{{ fmt(lead.source) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Campaign</dt><dd class="text-deck-text">{{ fmt(lead.source_campaign) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Medium</dt><dd class="text-deck-text">{{ fmt(lead.source_medium) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Resort interest</dt><dd class="text-deck-text">{{ fmt(lead.resort_interest) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Property type</dt><dd class="text-deck-text">{{ fmt(lead.property_type) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Est. value</dt><dd class="text-deck-text">${{ lead.estimated_value ?? '0.00' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Alt phone</dt><dd class="text-deck-text font-mono">{{ fmt(lead.alternate_phone) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Postal</dt><dd class="text-deck-text">{{ fmt(lead.postal_code) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Country</dt><dd class="text-deck-text">{{ fmt(lead.country) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Attempts</dt><dd class="text-deck-text">{{ lead.contact_attempts ?? 0 }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Last contacted</dt><dd class="text-deck-text">{{ lead.last_contacted_at?.split('T')[0] ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-deck-soft">Created</dt><dd class="text-deck-text">{{ lead.created_at?.split('T')[0] ?? '—' }}</dd></div>
                        </dl>
                    </section>

                    <!-- Notes timeline -->
                    <div class="lg:col-span-2 space-y-4">
                        <NotesPanel notable-type="lead" :notable-id="lead.id" :current-user-id="currentUserId" />

                        <!-- Conversion / pipeline -->
                        <ConvertLeadPanel
                            :lead-id="lead.id"
                            :lead-status="lead.status ?? 'new'"
                            :converted-customer-id="customer?.id ?? null"
                            @converted="loadLead"
                        />
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
