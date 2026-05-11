<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DisclosureChecklist from '@/Components/Compliance/DisclosureChecklist.vue';

/**
 * Compliance command center — recordings queue + refund cases +
 * chargebacks + DNC, all in one tabbed view. The recordings queue
 * is the regulatory-prevention surface; the cases queues are the
 * regulatory-response surfaces. Both matter for the same reason:
 * AG inquiries arrive with hours of notice.
 */

type Tab = 'recordings' | 'refunds' | 'chargebacks' | 'dnc';

interface DisclosureCaptures {
    tcpa_consent_captured: boolean;
    recording_disclosure_made: boolean;
    no_guarantee_disclosure_made: boolean;
    refund_policy_disclosure_made: boolean;
    total_fee_stated_clearly: boolean;
}
interface RecordingRow {
    id: string;
    compliance_status: string;
    call_id: string | null;
    deal_id: string | null;
    owner_id: string | null;
    agent_name: string | null;
    owner_name: string | null;
    listing_fee: number | null;
    agreement_status: string | null;
    call_duration: number | null;
    recording_url: string | null;
    captures: DisclosureCaptures;
    missing: string[];
    all_captured: boolean;
    review_notes: string | null;
    reviewed_at: string | null;
    created_at: string | null;
}
interface RecordingStats {
    total: number;
    pending: number;
    passed: number;
    failed: number;
    flagged: number;
    pass_rate: number | null;
}

interface RefundRow {
    id: string;
    deal_id: string;
    refund_amount: number;
    reason: string;
    is_high_risk: boolean;
    owner_statement: string | null;
    status: string;
    opened_at: string;
    resolved_at: string | null;
    owner_id: string;
    owner_name: string;
    opener_name: string | null;
    listing_fee: number | null;
    agreement_status: string | null;
}
interface RefundStats {
    open_count: number;
    resolved_count: number;
    escalated_count: number;
    open_amount: number;
    high_risk_count: number;
}

interface ChargebackRow {
    id: string;
    deal_id: string;
    processor_case_id: string;
    disputed_amount: number;
    reason_code: string;
    respond_by_date: string;
    days_until_due: number | null;
    is_overdue: boolean;
    is_urgent: boolean;
    status: string;
    owner_id: string;
    owner_name: string;
    listing_fee: number | null;
}
interface ChargebackStats {
    open_count: number;
    won_count: number;
    lost_count: number;
    open_amount: number;
    urgent_count: number;
    win_rate: number | null;
}

const tab = ref<Tab>('recordings');

// Recordings tab state
const recordingsStatus = ref<'pending_review' | 'failed' | 'flagged_for_audit' | 'passed' | 'all'>('pending_review');
const recordings = ref<RecordingRow[]>([]);
const recordingStats = ref<RecordingStats | null>(null);
const expandedRecordingId = ref<string | null>(null);
const reviewNotes = ref('');

// Refunds tab state
const refundStatus = ref<'open' | 'all' | 'opened' | 'investigating' | 'approved' | 'denied' | 'processed' | 'escalated_to_chargeback'>('open');
const refundHighRiskOnly = ref(false);
const refunds = ref<RefundRow[]>([]);
const refundStats = ref<RefundStats | null>(null);

// Chargebacks tab state
const chargebackStatus = ref<'open' | 'all' | 'received' | 'evidence_gathering' | 'evidence_submitted' | 'won' | 'lost'>('open');
const chargebackUrgentOnly = ref(false);
const chargebacks = ref<ChargebackRow[]>([]);
const chargebackStats = ref<ChargebackStats | null>(null);

const loading = ref(false);
const flash = ref<{ kind: 'ok' | 'err'; msg: string } | null>(null);

function showFlash(kind: 'ok' | 'err', msg: string): void {
    flash.value = { kind, msg };
    window.setTimeout(() => (flash.value = null), 4000);
}

async function loadRecordings(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: RecordingRow[]; stats: RecordingStats }>(
            '/api/compliance/recordings',
            { params: { status: recordingsStatus.value, per_page: 50 } },
        );
        recordings.value = data.data;
        recordingStats.value = data.stats;
    } finally {
        loading.value = false;
    }
}

async function loadRefunds(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: RefundRow[]; stats: RefundStats }>(
            '/api/compliance/refund-cases',
            {
                params: {
                    status: refundStatus.value,
                    high_risk: refundHighRiskOnly.value ? 1 : 0,
                    per_page: 50,
                },
            },
        );
        refunds.value = data.data;
        refundStats.value = data.stats;
    } finally {
        loading.value = false;
    }
}

async function loadChargebacks(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: ChargebackRow[]; stats: ChargebackStats }>(
            '/api/compliance/chargeback-cases',
            {
                params: {
                    status: chargebackStatus.value,
                    urgent_only: chargebackUrgentOnly.value ? 1 : 0,
                    per_page: 50,
                },
            },
        );
        chargebacks.value = data.data;
        chargebackStats.value = data.stats;
    } finally {
        loading.value = false;
    }
}

async function loadActive(): Promise<void> {
    if (tab.value === 'recordings') await loadRecordings();
    else if (tab.value === 'refunds') await loadRefunds();
    else if (tab.value === 'chargebacks') await loadChargebacks();
    // dnc tab links out to the existing /compliance/dnc page (D6 doesn't move it)
}

watch(tab, () => void loadActive());
watch(recordingsStatus, () => void loadRecordings());
watch([refundStatus, refundHighRiskOnly], () => void loadRefunds());
watch([chargebackStatus, chargebackUrgentOnly], () => void loadChargebacks());

onMounted(loadActive);

/* ------------------------------------------------------------------
 | Actions
 |------------------------------------------------------------------ */

async function transitionRecording(id: string, to: 'passed' | 'failed' | 'flagged_for_audit'): Promise<void> {
    try {
        await axios.post(`/api/compliance/recordings/${id}/transition`, { to, notes: reviewNotes.value });
        showFlash('ok', `Recording marked ${to.replace(/_/g, ' ')}.`);
        reviewNotes.value = '';
        expandedRecordingId.value = null;
        await loadRecordings();
    } catch {
        showFlash('err', 'Could not transition recording.');
    }
}

async function toggleRecordingMarker(id: string, field: keyof DisclosureCaptures, value: boolean): Promise<void> {
    try {
        await axios.post(`/api/compliance/recordings/${id}/toggle`, { field, value });
        await loadRecordings();
    } catch {
        showFlash('err', 'Could not toggle marker.');
    }
}

async function transitionRefund(id: string, to: string): Promise<void> {
    try {
        await axios.post(`/api/compliance/refund-cases/${id}/transition`, { to });
        showFlash('ok', `Refund case → ${to.replace(/_/g, ' ')}.`);
        await loadRefunds();
    } catch {
        showFlash('err', 'Could not transition refund case.');
    }
}

async function transitionChargeback(id: string, to: string): Promise<void> {
    try {
        await axios.post(`/api/compliance/chargeback-cases/${id}/transition`, { to });
        showFlash('ok', `Chargeback case → ${to.replace(/_/g, ' ')}.`);
        await loadChargebacks();
    } catch {
        showFlash('err', 'Could not transition chargeback case.');
    }
}

/* ------------------------------------------------------------------
 | Formatting helpers
 |------------------------------------------------------------------ */

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
function pct(n: number | null | undefined): string {
    if (n == null) return '—';
    return (n * 100).toFixed(1) + '%';
}
function relabel(s: string | null | undefined): string {
    return (s ?? '').replace(/_/g, ' ');
}

function complianceStatusColor(s: string): string {
    if (s === 'passed') return 'text-floor-win';
    if (s === 'failed' || s === 'flagged_for_audit') return 'text-floor-lose';
    return 'text-floor-accent';
}
function caseStatusColor(s: string): string {
    if (s === 'won' || s === 'processed' || s === 'approved') return 'text-floor-win';
    if (s === 'lost' || s === 'escalated_to_chargeback') return 'text-floor-lose';
    if (s === 'denied') return 'text-deck-soft';
    return 'text-floor-accent';
}
function chargebackUrgencyColor(row: ChargebackRow): string {
    if (row.is_overdue) return 'text-floor-lose font-bold';
    if (row.is_urgent) return 'text-floor-lose';
    if (row.days_until_due !== null && row.days_until_due <= 7) return 'text-floor-accent';
    return 'text-deck-soft';
}

const recordingPassRateClass = computed(() => {
    if (recordingStats.value?.pass_rate == null) return 'text-deck-dim';
    return recordingStats.value.pass_rate >= 0.95 ? 'text-floor-win'
        : recordingStats.value.pass_rate >= 0.85 ? 'text-floor-accent'
        : 'text-floor-lose';
});
</script>

<template>
    <AppLayout title="Compliance">
        <div class="p-6">
            <div class="mb-4">
                <h1 class="text-2xl font-semibold text-deck-text">Compliance</h1>
                <p class="text-sm text-deck-soft">
                    Pre-incident (disclosure capture) and post-incident (refund + chargeback) workflows. Audit-ready by design.
                </p>
            </div>

            <!-- Flash -->
            <div v-if="flash"
                 class="mb-4 rounded-md px-3 py-2 text-sm"
                 :class="flash.kind === 'ok'
                     ? 'border border-floor-win/30 bg-floor-win/10 text-floor-win'
                     : 'border border-floor-lose/30 bg-floor-lose/10 text-floor-lose'">
                {{ flash.msg }}
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-deck-line mb-4 overflow-x-auto">
                <button
                    v-for="t in (['recordings', 'refunds', 'chargebacks', 'dnc'] as const)"
                    :key="t"
                    class="px-4 py-2 text-sm capitalize border-b-2 -mb-px transition-colors whitespace-nowrap"
                    :class="tab === t
                        ? 'border-floor-accent text-deck-text font-medium'
                        : 'border-transparent text-deck-dim hover:text-deck-soft'"
                    @click="tab = t"
                >
                    <span v-if="t === 'recordings'">Disclosure queue</span>
                    <span v-else-if="t === 'refunds'">Refund cases</span>
                    <span v-else-if="t === 'chargebacks'">Chargebacks</span>
                    <span v-else>DNC list</span>
                </button>
            </div>

            <!-- ================================ RECORDINGS ================================ -->
            <template v-if="tab === 'recordings'">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                    <div class="deck-card p-4">
                        <div class="deck-label">Pass rate</div>
                        <div class="mt-1 deck-num text-2xl" :class="recordingPassRateClass">
                            {{ recordingStats ? pct(recordingStats.pass_rate) : '—' }}
                        </div>
                        <div class="text-[10px] font-mono text-deck-dim mt-0.5">target ≥ 95%</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Pending review</div>
                        <div class="mt-1 deck-num text-2xl text-floor-accent">{{ recordingStats?.pending || '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Passed</div>
                        <div class="mt-1 deck-num text-2xl text-floor-win">{{ recordingStats?.passed || '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Failed</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(recordingStats?.failed ?? 0) > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                            {{ recordingStats?.failed || '—' }}
                        </div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Flagged for audit</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(recordingStats?.flagged ?? 0) > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                            {{ recordingStats?.flagged || '—' }}
                        </div>
                    </div>
                </div>

                <!-- Status sub-filter -->
                <div class="flex items-center gap-2 mb-3 text-xs">
                    <label class="label">Status</label>
                    <select v-model="recordingsStatus" class="input text-xs py-1">
                        <option value="pending_review">Pending review</option>
                        <option value="failed">Failed</option>
                        <option value="flagged_for_audit">Flagged for audit</option>
                        <option value="passed">Passed</option>
                        <option value="all">All</option>
                    </select>
                </div>

                <div class="panel overflow-hidden">
                    <div v-if="!loading && recordings.length === 0" class="px-4 py-12 text-center text-sm text-deck-dim italic">
                        Nothing in the {{ recordingsStatus.replace(/_/g, ' ') }} queue. Clean board.
                    </div>
                    <ul v-else class="divide-y divide-deck-line/50">
                        <li v-for="r in recordings" :key="r.id" class="p-4">
                            <div class="flex items-start justify-between gap-3 flex-wrap">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-mono text-xs uppercase tracking-wider"
                                              :class="complianceStatusColor(r.compliance_status)">
                                            {{ relabel(r.compliance_status) }}
                                        </span>
                                        <span class="text-sm text-deck-text">{{ r.agent_name }}</span>
                                        <span class="text-xs text-deck-soft">
                                            with <Link v-if="r.owner_id" :href="`/owners/${r.owner_id}`" class="text-floor-accent hover:underline">{{ r.owner_name }}</Link>
                                        </span>
                                    </div>
                                    <div class="text-xs text-deck-soft mt-0.5">
                                        Listing fee <span class="font-mono">{{ fmtMoney(r.listing_fee) }}</span>
                                        · call {{ r.call_duration ? Math.round(r.call_duration / 60) : '?' }}m
                                        · {{ fmtDate(r.created_at) }}
                                    </div>
                                    <div v-if="r.missing.length" class="mt-1 text-[10px] font-mono uppercase tracking-wider text-floor-lose">
                                        Missing: {{ r.missing.map(relabel).join(' · ') }}
                                    </div>
                                </div>
                                <div class="flex flex-col gap-1.5 shrink-0">
                                    <a v-if="r.recording_url" :href="r.recording_url" target="_blank" rel="noopener"
                                       class="text-xs text-floor-accent hover:underline">▶ Play recording</a>
                                    <button class="btn-ghost text-xs"
                                            @click="expandedRecordingId = expandedRecordingId === r.id ? null : r.id">
                                        {{ expandedRecordingId === r.id ? 'Collapse' : 'Review' }}
                                    </button>
                                </div>
                            </div>

                            <!-- Expanded review panel -->
                            <div v-if="expandedRecordingId === r.id" class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <DisclosureChecklist
                                    :captures="r.captures"
                                    @toggle="(field, value) => toggleRecordingMarker(r.id, field, value)"
                                />
                                <div>
                                    <label class="label">Review notes</label>
                                    <textarea v-model="reviewNotes" rows="3"
                                              placeholder="What did the agent skip or misrepresent?"
                                              class="input mt-1 text-sm"></textarea>
                                    <div class="mt-3 flex gap-2 flex-wrap justify-end">
                                        <button class="btn-ghost text-xs" @click="transitionRecording(r.id, 'flagged_for_audit')">
                                            Flag for audit
                                        </button>
                                        <button class="btn-danger text-xs" @click="transitionRecording(r.id, 'failed')">
                                            Fail
                                        </button>
                                        <button class="btn-success text-xs" :disabled="!r.all_captured"
                                                @click="transitionRecording(r.id, 'passed')"
                                                :title="r.all_captured ? '' : 'Tick all five disclosures first'">
                                            Pass
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </template>

            <!-- ================================ REFUNDS ================================ -->
            <template v-if="tab === 'refunds'">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                    <div class="deck-card p-4">
                        <div class="deck-label">Open cases</div>
                        <div class="mt-1 deck-num text-2xl text-floor-accent">{{ refundStats?.open_count || '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Open amount</div>
                        <div class="mt-1 deck-num text-2xl">{{ refundStats ? fmtMoney(refundStats.open_amount) : '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">High-risk</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(refundStats?.high_risk_count ?? 0) > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                            {{ refundStats?.high_risk_count || '—' }}
                        </div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Resolved</div>
                        <div class="mt-1 deck-num text-2xl text-floor-win">{{ refundStats?.resolved_count || '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Escalated</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(refundStats?.escalated_count ?? 0) > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                            {{ refundStats?.escalated_count || '—' }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 mb-3 text-xs">
                    <label class="label">Status</label>
                    <select v-model="refundStatus" class="input text-xs py-1">
                        <option value="open">Open</option>
                        <option value="opened">Opened</option>
                        <option value="investigating">Investigating</option>
                        <option value="approved">Approved</option>
                        <option value="denied">Denied</option>
                        <option value="processed">Processed</option>
                        <option value="escalated_to_chargeback">Escalated</option>
                        <option value="all">All</option>
                    </select>
                    <label class="flex items-center gap-1.5 text-deck-soft">
                        <input type="checkbox" v-model="refundHighRiskOnly" class="rounded border-deck-line" />
                        High-risk only
                    </label>
                </div>

                <div class="panel overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-deck-line">
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Owner</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Reason</th>
                                <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Amount</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Opened</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                                <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-deck-line/50">
                            <tr v-if="!loading && refunds.length === 0">
                                <td colspan="6" class="px-3 py-12 text-center text-sm text-deck-dim italic">
                                    No matching refund cases. Quiet is good here.
                                </td>
                            </tr>
                            <tr v-for="c in refunds" :key="c.id" class="hover:bg-deck-raised/40">
                                <td class="px-3 py-2">
                                    <Link :href="`/owners/${c.owner_id}`" class="text-deck-text hover:text-floor-accent">
                                        {{ c.owner_name }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="font-mono uppercase tracking-wider"
                                          :class="c.is_high_risk ? 'text-floor-lose' : 'text-deck-soft'">
                                        {{ relabel(c.reason) }}{{ c.is_high_risk ? ' ⚠' : '' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right deck-num">{{ fmtMoney(c.refund_amount) }}</td>
                                <td class="px-3 py-2 font-mono tabular-nums text-xs text-deck-soft">{{ fmtDate(c.opened_at) }}</td>
                                <td class="px-3 py-2 font-mono text-xs uppercase tracking-wider" :class="caseStatusColor(c.status)">
                                    {{ relabel(c.status) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex gap-1">
                                        <button v-if="c.status === 'opened'" class="btn-ghost text-xs"
                                                @click="transitionRefund(c.id, 'investigating')">Investigate</button>
                                        <button v-if="['opened', 'investigating'].includes(c.status)" class="btn-ghost text-xs"
                                                @click="transitionRefund(c.id, 'approved')">Approve</button>
                                        <button v-if="['opened', 'investigating'].includes(c.status)" class="btn-ghost text-xs"
                                                @click="transitionRefund(c.id, 'denied')">Deny</button>
                                        <button v-if="c.status === 'approved'" class="btn-success text-xs"
                                                @click="transitionRefund(c.id, 'processed')">Mark processed</button>
                                        <button v-if="['opened', 'investigating', 'approved'].includes(c.status)"
                                                class="btn-ghost text-xs text-floor-lose"
                                                @click="transitionRefund(c.id, 'escalated_to_chargeback')">Escalate</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ================================ CHARGEBACKS ================================ -->
            <template v-if="tab === 'chargebacks'">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5 mb-4">
                    <div class="deck-card p-4">
                        <div class="deck-label">Open</div>
                        <div class="mt-1 deck-num text-2xl text-floor-accent">{{ chargebackStats?.open_count || '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Urgent (≤3d)</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(chargebackStats?.urgent_count ?? 0) > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                            {{ chargebackStats?.urgent_count || '—' }}
                        </div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">At risk</div>
                        <div class="mt-1 deck-num text-2xl">{{ chargebackStats ? fmtMoney(chargebackStats.open_amount) : '—' }}</div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Win rate</div>
                        <div class="mt-1 deck-num text-2xl"
                             :class="(chargebackStats?.win_rate ?? 0) >= 0.5 ? 'text-floor-win' : 'text-floor-lose'">
                            {{ chargebackStats ? pct(chargebackStats.win_rate) : '—' }}
                        </div>
                    </div>
                    <div class="deck-card p-4">
                        <div class="deck-label">Won / Lost</div>
                        <div class="mt-1 deck-num text-xl">
                            <span class="text-floor-win">{{ chargebackStats?.won_count ?? 0 }}</span>
                            <span class="text-deck-dim"> / </span>
                            <span class="text-floor-lose">{{ chargebackStats?.lost_count ?? 0 }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 mb-3 text-xs">
                    <label class="label">Status</label>
                    <select v-model="chargebackStatus" class="input text-xs py-1">
                        <option value="open">Open</option>
                        <option value="received">Received</option>
                        <option value="evidence_gathering">Evidence gathering</option>
                        <option value="evidence_submitted">Evidence submitted</option>
                        <option value="won">Won</option>
                        <option value="lost">Lost</option>
                        <option value="all">All</option>
                    </select>
                    <label class="flex items-center gap-1.5 text-deck-soft">
                        <input type="checkbox" v-model="chargebackUrgentOnly" class="rounded border-deck-line" />
                        Urgent only
                    </label>
                </div>

                <div class="panel overflow-hidden">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-deck-line">
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Owner / Case</th>
                                <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Disputed</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Reason</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Respond by</th>
                                <th class="px-3 py-2 text-left text-[10px] font-mono uppercase tracking-wider text-deck-dim">Status</th>
                                <th class="px-3 py-2 text-right text-[10px] font-mono uppercase tracking-wider text-deck-dim">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-deck-line/50">
                            <tr v-if="!loading && chargebacks.length === 0">
                                <td colspan="6" class="px-3 py-12 text-center text-sm text-deck-dim italic">
                                    No chargeback cases in this view. That's the goal.
                                </td>
                            </tr>
                            <tr v-for="c in chargebacks" :key="c.id" class="hover:bg-deck-raised/40">
                                <td class="px-3 py-2">
                                    <Link :href="`/owners/${c.owner_id}`" class="text-deck-text hover:text-floor-accent">
                                        {{ c.owner_name }}
                                    </Link>
                                    <div class="text-[10px] font-mono text-deck-dim">{{ c.processor_case_id }}</div>
                                </td>
                                <td class="px-3 py-2 text-right deck-num">{{ fmtMoney(c.disputed_amount) }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-deck-soft">{{ c.reason_code }}</td>
                                <td class="px-3 py-2 font-mono tabular-nums text-xs" :class="chargebackUrgencyColor(c)">
                                    {{ c.respond_by_date }}
                                    <span v-if="c.is_overdue" class="ml-1">OVERDUE</span>
                                    <span v-else-if="c.days_until_due !== null" class="ml-1">({{ c.days_until_due }}d)</span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs uppercase tracking-wider" :class="caseStatusColor(c.status)">
                                    {{ relabel(c.status) }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex gap-1">
                                        <button v-if="c.status === 'received'" class="btn-ghost text-xs"
                                                @click="transitionChargeback(c.id, 'evidence_gathering')">Gather evidence</button>
                                        <button v-if="c.status === 'evidence_gathering'" class="btn-primary text-xs"
                                                @click="transitionChargeback(c.id, 'evidence_submitted')">Submit</button>
                                        <button v-if="c.status === 'evidence_submitted'" class="btn-success text-xs"
                                                @click="transitionChargeback(c.id, 'won')">Won</button>
                                        <button v-if="c.status === 'evidence_submitted'" class="btn-danger text-xs"
                                                @click="transitionChargeback(c.id, 'lost')">Lost</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- ================================ DNC ================================ -->
            <template v-if="tab === 'dnc'">
                <div class="panel p-8 text-center">
                    <p class="text-sm text-deck-soft mb-3">
                        Do-Not-Call list management is on its own page.
                    </p>
                    <Link href="/compliance/dnc" class="btn-primary text-xs inline-block">Open DNC list →</Link>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
