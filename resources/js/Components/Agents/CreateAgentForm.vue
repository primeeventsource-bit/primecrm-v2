<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

const emit = defineEmits<{ (e: 'created'): void; (e: 'cancel'): void }>();

type PayType = 'hourly' | 'salary' | 'commission_only' | 'hybrid';

interface CommissionPlanOption {
    id: string;
    name: string;
    description: string | null;
}

const form = ref({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    role: 'closer' as string,
    phone: '',
    extension: '',
    timezone: 'America/New_York',
    is_panama_based: false,

    // Compensation package
    pay_type: 'commission_only' as PayType,
    base_rate: '' as string, // decimal dollars; backend converts to cents
    pay_currency: 'USD',
    pay_notes: '',

    // Commission assignment
    commission_plan_id: '' as string,
    commission_override_rate: '' as string, // percent (e.g. 12 = 12%)
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const plans = ref<CommissionPlanOption[]>([]);
const plansLoading = ref(true);
const plansError = ref<string | null>(null);

// Show the base-rate input unless the agent is purely commission-only.
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
        const res = await axios.get('/api/commission/plans', { params: { active_only: true } });
        plans.value = res.data?.data ?? [];
    } catch {
        plansError.value = 'Could not load commission plans.';
    } finally {
        plansLoading.value = false;
    }
});

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload: Record<string, unknown> = { ...form.value };

    // Drop empty optional strings so backend `nullable` validation passes.
    for (const k of Object.keys(payload)) {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    }

    // Numeric fields: send as numbers, not strings.
    if (payload.base_rate !== undefined) {
        payload.base_rate = Number(payload.base_rate);
    }
    if (payload.commission_override_rate !== undefined) {
        payload.commission_override_rate = Number(payload.commission_override_rate);
    }

    try {
        await axios.post('/api/agents', payload);
        emit('created');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; error?: string; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length) {
            errors.value._global = [e.response?.data?.error ?? e.response?.data?.message ?? 'Failed to create agent'];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errors._global[0] }}
        </div>

        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">First name <span class="text-red-600">*</span></label>
                <input v-model="form.first_name" type="text" class="input mt-1" required />
                <p v-if="errors.first_name" class="mt-1 text-xs text-red-600">{{ errors.first_name[0] }}</p>
            </div>
            <div>
                <label class="label">Last name <span class="text-red-600">*</span></label>
                <input v-model="form.last_name" type="text" class="input mt-1" required />
                <p v-if="errors.last_name" class="mt-1 text-xs text-red-600">{{ errors.last_name[0] }}</p>
            </div>
            <div>
                <label class="label">Email <span class="text-red-600">*</span></label>
                <input v-model="form.email" type="email" class="input mt-1" required />
                <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="label">Password <span class="text-red-600">*</span></label>
                <input v-model="form.password" type="password" class="input mt-1" required minlength="8" />
                <p v-if="errors.password" class="mt-1 text-xs text-red-600">{{ errors.password[0] }}</p>
            </div>
            <div>
                <label class="label">Role <span class="text-red-600">*</span></label>
                <select v-model="form.role" class="input mt-1" required>
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
                    <p v-if="errors.pay_type" class="mt-1 text-xs text-red-600">{{ errors.pay_type[0] }}</p>
                </div>
                <div v-if="needsBaseRate">
                    <label class="label">{{ baseRateLabel }}</label>
                    <div class="mt-1 flex items-stretch">
                        <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">
                            $
                        </span>
                        <input
                            v-model="form.base_rate"
                            type="number"
                            min="0"
                            step="0.01"
                            class="input rounded-l-none"
                            :placeholder="form.pay_type === 'salary' ? '65000.00' : '18.50'"
                        />
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
                    <p v-if="errors.pay_currency" class="mt-1 text-xs text-red-600">{{ errors.pay_currency[0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="label">Package notes</label>
                    <textarea
                        v-model="form.pay_notes"
                        rows="2"
                        maxlength="500"
                        class="input mt-1"
                        placeholder="e.g. $18.50/hr base + 6% commission, $250 weekly perfect-attendance bonus"
                    />
                    <p v-if="errors.pay_notes" class="mt-1 text-xs text-red-600">{{ errors.pay_notes[0] }}</p>
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-md border border-slate-200 p-3">
            <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Commission</legend>

            <p v-if="plansLoading" class="text-xs text-slate-500">Loading plans…</p>
            <p v-else-if="plansError" class="text-xs text-red-600">{{ plansError }}</p>
            <p v-else-if="plans.length === 0" class="text-xs text-slate-500">
                No commission plans configured. You can leave this blank and assign one later from Commission settings.
            </p>

            <div v-if="!plansLoading && plans.length > 0" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Plan</label>
                    <select v-model="form.commission_plan_id" class="input mt-1">
                        <option value="">— No plan / set later —</option>
                        <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <p v-if="errors.commission_plan_id" class="mt-1 text-xs text-red-600">{{ errors.commission_plan_id[0] }}</p>
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
                            placeholder="e.g. 12"
                        />
                        <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">%</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Optional — overrides the plan's default rate for this agent only.</p>
                    <p v-if="errors.commission_override_rate" class="mt-1 text-xs text-red-600">{{ errors.commission_override_rate[0] }}</p>
                </div>
            </div>
        </fieldset>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? 'Saving…' : 'Create agent' }}
            </button>
        </div>
    </form>
</template>
