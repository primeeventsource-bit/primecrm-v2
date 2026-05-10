<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types/api';

/**
 * Drives the lead → sale → customer pipeline from the Lead detail page.
 *
 * Flow:
 *   1. Click "Start a deal" → POST /api/deals (lead_id + agent_id + total_value)
 *      Stage starts at 'new'.
 *   2. Stage buttons → POST /api/deals/{id}/advance-stage
 *   3. When stage reaches 'closed_won', the backend fires DealClosedWon,
 *      which the Customer module listens for and creates the Customer
 *      automatically (CreateCustomerFromLead).
 *
 * The component refetches existing deals on the lead so reloading the
 * page picks up where the closer left off.
 */

const props = defineProps<{
    leadId: string;
    leadStatus: string;
    convertedCustomerId: string | null;
}>();
const emit = defineEmits<{ (e: 'converted'): void }>();

const page = usePage<PageProps>();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);

interface Deal {
    id: string;
    lead_id: string;
    agent_id: string;
    stage: string;
    total_value: string | number;
    snr_amount: string | number;
    vd_amount: string | number;
    payable_amount: string | number;
    currency: string;
    closed_at: string | null;
    stage_changed_at: string | null;
    created_at: string | null;
}

const STAGES: { value: string; label: string }[] = [
    { value: 'new', label: 'New' },
    { value: 'contacted', label: 'Contacted' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'pitch_presented', label: 'Pitch presented' },
    { value: 'negotiating', label: 'Negotiating' },
    { value: 'closed_won', label: 'Closed won' },
    { value: 'closed_lost', label: 'Closed lost' },
];

const deals = ref<Deal[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const showCreate = ref(false);
const totalValue = ref<number | null>(null);
const snrAmount = ref<number>(0);
const vdAmount = ref<number>(0);
const submitting = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<{ data: Deal[] }>('/api/deals', {
            params: { lead_id: props.leadId, per_page: 50 },
        });
        deals.value = data.data;
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load deals.';
    } finally {
        loading.value = false;
    }
}

async function createDeal(): Promise<void> {
    if (!currentUserId.value || totalValue.value === null) return;
    submitting.value = true;
    error.value = null;
    try {
        await axios.post('/api/deals', {
            lead_id: props.leadId,
            agent_id: currentUserId.value,
            total_value: totalValue.value,
            snr_amount: snrAmount.value || 0,
            vd_amount: vdAmount.value || 0,
            currency: 'USD',
        });
        showCreate.value = false;
        totalValue.value = null;
        snrAmount.value = 0;
        vdAmount.value = 0;
        await load();
    } catch (e: unknown) {
        const r = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data;
        const firstFieldErr = r?.errors ? Object.values(r.errors)[0]?.[0] : undefined;
        error.value = firstFieldErr ?? r?.message ?? 'Could not create deal.';
    } finally {
        submitting.value = false;
    }
}

async function advance(deal: Deal, stage: string): Promise<void> {
    if (deal.stage === stage) return;
    if (stage === 'closed_lost' && !confirm('Mark this deal as lost?')) return;
    if (stage === 'closed_won' && !confirm('Close this deal as won? This creates a Customer record.')) return;

    error.value = null;
    try {
        await axios.post(`/api/deals/${deal.id}/advance-stage`, { stage });
        await load();
        // closed_won triggers customer creation (listener); the parent
        // should refetch its linked-customer panel.
        if (stage === 'closed_won') emit('converted');
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not advance stage.';
    }
}

function stageBadge(stage: string): string {
    if (stage === 'closed_won') return 'bg-emerald-100 text-emerald-700';
    if (stage === 'closed_lost') return 'bg-rose-100 text-rose-700';
    if (stage === 'negotiating' || stage === 'pitch_presented') return 'bg-amber-100 text-amber-700';
    if (stage === 'qualified') return 'bg-blue-100 text-blue-700';
    return 'bg-slate-100 text-slate-700';
}

function isTerminal(stage: string): boolean {
    return stage === 'closed_won' || stage === 'closed_lost';
}

watch(() => props.leadId, () => void load());
onMounted(load);
</script>

<template>
    <section class="panel">
        <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
            <div>
                <h3 class="text-sm font-semibold text-deck-text">Deals & conversion</h3>
                <p class="mt-0.5 text-xs text-deck-soft">
                    A Customer is created automatically when a deal closes won.
                </p>
            </div>
            <button
                v-if="!showCreate"
                class="btn-primary text-sm"
                @click="showCreate = true"
            >+ Start a deal</button>
        </header>

        <!-- New deal form -->
        <form v-if="showCreate" class="border-b border-deck-line px-4 py-3 space-y-2" @submit.prevent="createDeal">
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="label">Total ($) *</label>
                    <input v-model.number="totalValue" type="number" min="0" step="0.01" required class="input mt-1" />
                </div>
                <div>
                    <label class="label">SNR ($)</label>
                    <input v-model.number="snrAmount" type="number" min="0" step="0.01" class="input mt-1" />
                </div>
                <div>
                    <label class="label">VD ($)</label>
                    <input v-model.number="vdAmount" type="number" min="0" step="0.01" class="input mt-1" />
                </div>
            </div>
            <p v-if="error" class="text-xs text-rose-600">{{ error }}</p>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" class="btn-ghost text-sm" @click="showCreate = false">Cancel</button>
                <button type="submit" class="btn-primary text-sm" :disabled="submitting || !totalValue">
                    {{ submitting ? 'Creating…' : 'Create deal' }}
                </button>
            </div>
        </form>

        <p v-else-if="error" class="px-4 py-2 text-xs text-rose-600">{{ error }}</p>

        <!-- Deal list -->
        <ul v-if="deals.length" class="divide-y divide-deck-line">
            <li v-for="d in deals" :key="d.id" class="px-4 py-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="pill" :class="stageBadge(d.stage)">{{ d.stage.replace(/_/g, ' ') }}</span>
                            <span class="text-sm font-mono tabular-nums text-deck-text">${{ d.total_value }}</span>
                            <span class="text-xs text-deck-soft">net ${{ d.payable_amount }}</span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-deck-dim">
                            opened {{ d.created_at?.split('T')[0] ?? '—' }}
                            <span v-if="d.closed_at"> · closed {{ d.closed_at.split('T')[0] }}</span>
                        </div>
                    </div>
                </div>

                <!-- Stage buttons -->
                <div v-if="!isTerminal(d.stage)" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="s in STAGES"
                        :key="s.value"
                        type="button"
                        class="rounded-md px-2 py-1 text-xs"
                        :class="d.stage === s.value
                            ? 'bg-floor-accent text-deck-bg font-semibold'
                            : 'border border-deck-line text-deck-soft hover:bg-deck-raised hover:text-deck-text'"
                        :disabled="d.stage === s.value"
                        @click="advance(d, s.value)"
                    >{{ s.label }}</button>
                </div>
                <div v-else-if="d.stage === 'closed_won'" class="mt-2 text-xs text-emerald-600">
                    ✓ Won. Customer record created automatically.
                </div>
                <div v-else class="mt-2 text-xs text-rose-600">
                    Closed lost — final.
                </div>
            </li>
        </ul>
        <div v-else-if="!loading && !showCreate" class="px-4 py-6 text-center text-sm text-deck-soft">
            No deals yet. Start one above to begin moving this lead through the pipeline.
        </div>
    </section>
</template>
