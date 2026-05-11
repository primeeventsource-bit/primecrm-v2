<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps<{ agentId: string }>();
const emit = defineEmits<{ (e: 'updated'): void; (e: 'cancel'): void }>();

type PayType = 'hourly' | 'salary' | 'commission_only' | 'hybrid';

interface CommissionPlanOption {
    id: string;
    name: string;
    description: string | null;
}

interface AgentLoaded {
    id: string;
    first_name: string;
    last_name: string;
    email: string;
    role: string;
    phone: string | null;
    extension: string | null;
    timezone: string | null;
    is_panama_based: boolean;
    status?: string;
    pay_type: PayType | null;
    base_rate_cents: number | null;
    pay_currency: string | null;
    pay_notes: string | null;
    commission: {
        plan_id: string;
        plan_name: string;
        effective_from: string | null;
        override_rate_pct: number | null;
    } | null;
}

const loading = ref(true);
const loadError = ref<string | null>(null);
const submitting = ref(false);
const errors = ref<Record<string, string[]>>({});
const plans = ref<CommissionPlanOption[]>([]);

// Initial snapshot — used so we can submit only changed fields. Mutating
// the user's role/status etc. unintentionally is the kind of bug that
// shows up later in audit logs ("why did Marcus suddenly become an admin
// on 2026-05-11?").
const initial = ref<AgentLoaded | null>(null);

const form = ref({
    first_name: '',
    last_name: '',
    role: 'closer' as string,
    phone: '',
    extension: '',
    timezone: 'America/New_York',
    is_panama_based: false,
    status: 'active' as string,

    pay_type: 'commission_only' as PayType,
    base_rate: '' as string,
    pay_currency: 'USD',
    pay_notes: '',

    commission_plan_id: '' as string,
    commission_override_rate: '' as string,
});

const needsBaseRate = computed(() => form.value.pay_type !== 'commission_only');
const baseRateLabel = computed(() => {
    if (form.value.pay_type === 'hourly' || form.value.pay_type === 'hybrid') return 'Hourly rate';
    if (form.value.pay_type === 'salary') return 'Annual salary';
    return 'Base rate';
});
const baseRateSuffix = computed(() => {
    if (form.value.pay_type === 'hourly' || form.value.pay_type === 'hybrid') return '/ hr';
    if (form.value.pay_type === 'salary') return '/ yr';
    return '';
});

onMounted(async () => {
    try {
        const [agentRes, plansRes] = await Promise.all([
            axios.get<AgentLoaded>(`/api/agents/${props.agentId}`),
            axios.get<{ data: CommissionPlanOption[] }>('/api/commission/plans', { params: { active_only: true } }),
        ]);
        const a = agentRes.data;
        initial.value = a;
        plans.value = plansRes.data?.data ?? [];

        form.value = {
            first_name: a.first_name ?? '',
            last_name: a.last_name ?? '',
            role: a.role,
            phone: a.phone ?? '',
            extension: a.extension ?? '',
            timezone: a.timezone ?? 'America/New_York',
            is_panama_based: a.is_panama_based,
            status: a.status ?? 'active',

            pay_type: (a.pay_type ?? 'commission_only') as PayType,
            base_rate: a.base_rate_cents != null ? (a.base_rate_cents / 100).toFixed(2) : '',
            pay_currency: a.pay_currency ?? 'USD',
            pay_notes: a.pay_notes ?? '',

            commission_plan_id: a.commission?.plan_id ?? '',
            commission_override_rate: a.commission?.override_rate_pct != null
                ? String(a.commission.override_rate_pct)
                : '',
        };
    } catch {
        loadError.value = 'Could not load agent.';
    } finally {
        loading.value = false;
    }
});

/**
 * Build a minimal patch: only include fields whose value differs from the
 * loaded snapshot. Cleared commission/base_rate is sent as explicit null
 * (server treats null as "clear", undefined as "unchanged").
 */
function buildPatch(): Record<string, unknown> {
    if (!initial.value) return {};
    const a = initial.value;
    const patch: Record<string, unknown> = {};

    if (form.value.first_name !== (a.first_name ?? '')) patch.first_name = form.value.first_name;
    if (form.value.last_name !== (a.last_name ?? '')) patch.last_name = form.value.last_name;
    if (form.value.role !== a.role) patch.role = form.value.role;
    if (form.value.phone !== (a.phone ?? '')) patch.phone = form.value.phone || null;
    if (form.value.extension !== (a.extension ?? '')) patch.extension = form.value.extension || null;
    if (form.value.timezone !== (a.timezone ?? 'America/New_York')) patch.timezone = form.value.timezone;
    if (form.value.is_panama_based !== a.is_panama_based) patch.is_panama_based = form.value.is_panama_based;
    if (form.value.status !== (a.status ?? 'active')) patch.status = form.value.status;

    // Compensation
    if (form.value.pay_type !== (a.pay_type ?? 'commission_only')) patch.pay_type = form.value.pay_type;

    const newRate = form.value.base_rate === '' ? null : Number(form.value.base_rate);
    const oldRate = a.base_rate_cents != null ? a.base_rate_cents / 100 : null;
    if (newRate !== oldRate) patch.base_rate = newRate;

    if (form.value.pay_currency !== (a.pay_currency ?? 'USD')) patch.pay_currency = form.value.pay_currency || null;
    if (form.value.pay_notes !== (a.pay_notes ?? '')) patch.pay_notes = form.value.pay_notes || null;

    // Commission — send both fields together if the plan changed,
    // since override on a different plan is a different concept.
    const newPlanId = form.value.commission_plan_id || null;
    const oldPlanId = a.commission?.plan_id ?? null;
    const newOverride = form.value.commission_override_rate === '' ? null : Number(form.value.commission_override_rate);
    const oldOverride = a.commission?.override_rate_pct ?? null;

    if (newPlanId !== oldPlanId || newOverride !== oldOverride) {
        patch.commission_plan_id = newPlanId;
        // Override only meaningful when a plan is set.
        patch.commission_override_rate = newPlanId === null ? null : newOverride;
    }

    return patch;
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const patch = buildPatch();
    if (Object.keys(patch).length === 0) {
        emit('cancel');
        submitting.value = false;
        return;
    }

    try {
        await axios.patch(`/api/agents/${props.agentId}`, patch);
        emit('updated');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; error?: string; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length) {
            errors.value._global = [e.response?.data?.error ?? e.response?.data?.message ?? 'Failed to update agent'];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div v-if="loading" class="p-6 text-center text-sm text-slate-500">Loading agent…</div>
    <div v-else-if="loadError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
        {{ loadError }}
    </div>
    <form v-else class="space-y-5" @submit.prevent="submit">
        <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errors._global[0] }}
        </div>

        <p class="text-xs text-slate-500">
            Editing <span class="font-medium text-slate-700">{{ initial?.first_name }} {{ initial?.last_name }}</span>
            <span class="text-slate-400">· {{ initial?.email }}</span>
        </p>

        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">First name</label>
                <input v-model="form.first_name" type="text" class="input mt-1" />
                <p v-if="errors.first_name" class="mt-1 text-xs text-red-600">{{ errors.first_name[0] }}</p>
            </div>
            <div>
                <label class="label">Last name</label>
                <input v-model="form.last_name" type="text" class="input mt-1" />
                <p v-if="errors.last_name" class="mt-1 text-xs text-red-600">{{ errors.last_name[0] }}</p>
            </div>
            <div>
                <label class="label">Role</label>
                <select v-model="form.role" class="input mt-1">
                    <option value="closer">Closer</option>
                    <option value="fronter">Fronter</option>
                    <option value="agent">Agent</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="manager">Manager</option>
                    <option value="qa">QA</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label class="label">Status</label>
                <select v-model="form.status" class="input mt-1">
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                    <option value="terminated">Terminated</option>
                </select>
            </div>
            <div>
                <label class="label">Location</label>
                <select v-model="form.is_panama_based" class="input mt-1">
                    <option :value="false">United States</option>
                    <option :value="true">Panama</option>
                </select>
            </div>
            <div>
                <label class="label">Phone</label>
                <input v-model="form.phone" type="tel" class="input mt-1" />
            </div>
            <div>
                <label class="label">Extension</label>
                <input v-model="form.extension" type="text" class="input mt-1" maxlength="16" />
            </div>
            <div>
                <label class="label">Timezone</label>
                <input v-model="form.timezone" type="text" class="input mt-1" maxlength="64" />
            </div>
        </fieldset>

        <fieldset class="rounded-md border border-slate-200 p-3">
            <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Compensation</legend>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Pay type</label>
                    <select v-model="form.pay_type" class="input mt-1">
                        <option value="commission_only">Commission only</option>
                        <option value="hourly">Hourly + commission</option>
                        <option value="salary">Salary + commission</option>
                        <option value="hybrid">Hybrid (hourly draw)</option>
                    </select>
                </div>
                <div v-if="needsBaseRate">
                    <label class="label">{{ baseRateLabel }}</label>
                    <div class="mt-1 flex items-stretch">
                        <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">$</span>
                        <input v-model="form.base_rate" type="number" min="0" step="0.01" class="input rounded-l-none" />
                        <span v-if="baseRateSuffix" class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">
                            {{ baseRateSuffix }}
                        </span>
                    </div>
                    <p v-if="errors.base_rate" class="mt-1 text-xs text-red-600">{{ errors.base_rate[0] }}</p>
                </div>
                <div v-if="needsBaseRate">
                    <label class="label">Currency</label>
                    <select v-model="form.pay_currency" class="input mt-1">
                        <option value="USD">USD</option>
                        <option value="PAB">PAB (Panama Balboa)</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="label">Package notes</label>
                    <textarea v-model="form.pay_notes" rows="2" maxlength="500" class="input mt-1" />
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-md border border-slate-200 p-3">
            <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Commission</legend>

            <p v-if="plans.length === 0" class="text-xs text-slate-500">
                No commission plans configured. You can leave this blank.
            </p>

            <div v-else class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Plan</label>
                    <select v-model="form.commission_plan_id" class="input mt-1">
                        <option value="">— No plan —</option>
                        <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <p v-if="initial?.commission" class="mt-1 text-xs text-slate-400">
                        Current: {{ initial.commission.plan_name }} since {{ initial.commission.effective_from }}
                    </p>
                </div>
                <div>
                    <label class="label">Override rate</label>
                    <div class="mt-1 flex items-stretch">
                        <input
                            v-model="form.commission_override_rate"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="input rounded-r-none"
                            :disabled="!form.commission_plan_id"
                        />
                        <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">%</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Changing plan ends the current assignment and starts a new one.
                    </p>
                </div>
            </div>
        </fieldset>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? 'Saving…' : 'Save changes' }}
            </button>
        </div>
    </form>
</template>
