<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

type RuleType = 'percentage' | 'flat' | 'override' | 'tiered' | 'bonus';
type Role = 'closer' | 'fronter' | 'supervisor' | 'qa' | 'override';

interface RuleInput {
    id?: string;
    role: Role;
    rule_type: RuleType;
    trigger_event: string;
    config: Record<string, unknown>;
    priority: number;
    active: boolean;
}

const props = defineProps<{ open: boolean; planId: string; rule: RuleInput | null }>();
const emit = defineEmits<{ (e: 'saved'): void; (e: 'close'): void }>();

const form = ref({
    role: 'closer' as Role,
    rule_type: 'percentage' as RuleType,
    trigger_event: 'payment.cleared',
    priority: 10,
    active: true,

    // Per-type fields (flattened for the form, repacked into `config` on submit)
    pct_rate: '8' as string,        // percentage rule, as %
    pct_base_field: 'amount',
    flat_amount: '' as string,      // flat rule, dollars
    override_rate: '1' as string,   // override rule, as %
    raw_config: '' as string,       // fallback for tiered/bonus
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

watch(
    () => [props.open, props.rule],
    () => {
        if (!props.open) return;
        errors.value = {};
        const r = props.rule;
        if (r) {
            const cfg = r.config ?? {};
            form.value = {
                role: r.role,
                rule_type: r.rule_type,
                trigger_event: r.trigger_event,
                priority: r.priority,
                active: r.active,
                pct_rate: cfg.rate != null ? String((Number(cfg.rate) * 100).toFixed(2)).replace(/\.00$/, '') : '8',
                pct_base_field: (cfg.base_field as string) ?? 'amount',
                flat_amount: cfg.amount != null ? String(cfg.amount) : '',
                override_rate: cfg.override_rate != null ? String((Number(cfg.override_rate) * 100).toFixed(2)).replace(/\.00$/, '') : '1',
                raw_config: JSON.stringify(cfg, null, 2),
            };
        } else {
            form.value = {
                role: 'closer',
                rule_type: 'percentage',
                trigger_event: 'payment.cleared',
                priority: 10,
                active: true,
                pct_rate: '8',
                pct_base_field: 'amount',
                flat_amount: '',
                override_rate: '1',
                raw_config: '',
            };
        }
    },
    { immediate: true },
);

const showPercentageFields = computed(() => form.value.rule_type === 'percentage');
const showFlatFields = computed(() => form.value.rule_type === 'flat');
const showOverrideFields = computed(() => form.value.rule_type === 'override');
const showRawJsonFields = computed(() => form.value.rule_type === 'tiered' || form.value.rule_type === 'bonus');

function buildConfig(): Record<string, unknown> | null {
    switch (form.value.rule_type) {
        case 'percentage':
            return {
                rate: Number(form.value.pct_rate) / 100,
                base_field: form.value.pct_base_field || 'amount',
            };
        case 'flat':
            return { amount: Number(form.value.flat_amount) };
        case 'override':
            return { override_rate: Number(form.value.override_rate) / 100 };
        case 'tiered':
        case 'bonus':
            // For complex types we accept hand-edited JSON. Catch parse
            // errors here so we surface them as a form error rather than
            // a 422 from the server.
            try {
                return JSON.parse(form.value.raw_config || '{}');
            } catch {
                return null;
            }
    }
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const config = buildConfig();
    if (config === null) {
        errors.value.config = ['Config JSON is not valid.'];
        submitting.value = false;
        return;
    }

    const payload = {
        role: form.value.role,
        rule_type: form.value.rule_type,
        trigger_event: form.value.trigger_event,
        config,
        priority: form.value.priority,
        active: form.value.active,
    };

    try {
        if (props.rule?.id) {
            await axios.patch(`/api/commission/plans/${props.planId}/rules/${props.rule.id}`, payload);
        } else {
            await axios.post(`/api/commission/plans/${props.planId}/rules`, payload);
        }
        emit('saved');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; error?: string; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length) {
            errors.value._global = [e.response?.data?.error ?? e.response?.data?.message ?? 'Save failed'];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Modal :open="open" :title="rule?.id ? 'Edit rule' : 'Add rule'" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ errors._global[0] }}
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Recipient role</label>
                    <select v-model="form.role" class="input mt-1">
                        <option value="closer">Closer</option>
                        <option value="fronter">Fronter</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="qa">QA</option>
                        <option value="override">Override (supervisor/manager)</option>
                    </select>
                </div>
                <div>
                    <label class="label">Rule type</label>
                    <select v-model="form.rule_type" class="input mt-1">
                        <option value="percentage">Percentage</option>
                        <option value="flat">Flat amount</option>
                        <option value="override">Override on others' commission</option>
                        <option value="tiered">Tiered (advanced)</option>
                        <option value="bonus">Bonus (advanced)</option>
                    </select>
                </div>
                <div>
                    <label class="label">Trigger event</label>
                    <input v-model="form.trigger_event" type="text" class="input mt-1" maxlength="80" />
                    <p class="mt-1 text-xs text-slate-500">e.g. payment.cleared, deal.closed_won, booking.confirmed</p>
                </div>
                <div>
                    <label class="label">Priority</label>
                    <input v-model.number="form.priority" type="number" min="0" max="1000" class="input mt-1" />
                    <p class="mt-1 text-xs text-slate-500">Higher wins on overlap. Default 10.</p>
                </div>
            </div>

            <!-- Type-specific fields -->
            <div v-if="showPercentageFields" class="grid grid-cols-2 gap-3 rounded-md border border-slate-200 p-3">
                <div>
                    <label class="label">Rate (%)</label>
                    <div class="mt-1 flex items-stretch">
                        <input v-model="form.pct_rate" type="number" min="0" max="100" step="0.01" class="input rounded-r-none" />
                        <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">%</span>
                    </div>
                </div>
                <div>
                    <label class="label">Base field</label>
                    <input v-model="form.pct_base_field" type="text" class="input mt-1" />
                    <p class="mt-1 text-xs text-slate-500">Payload key the rate applies to. Usually <code>amount</code>.</p>
                </div>
            </div>

            <div v-if="showFlatFields" class="rounded-md border border-slate-200 p-3">
                <label class="label">Flat amount ($)</label>
                <div class="mt-1 flex items-stretch">
                    <span class="inline-flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">$</span>
                    <input v-model="form.flat_amount" type="number" min="0" step="0.01" class="input rounded-l-none" />
                </div>
            </div>

            <div v-if="showOverrideFields" class="rounded-md border border-slate-200 p-3">
                <label class="label">Override rate (% of the closer's commission)</label>
                <div class="mt-1 flex items-stretch">
                    <input v-model="form.override_rate" type="number" min="0" max="100" step="0.01" class="input rounded-r-none" />
                    <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-sm text-slate-500">%</span>
                </div>
            </div>

            <div v-if="showRawJsonFields" class="rounded-md border border-amber-200 bg-amber-50 p-3">
                <label class="label">Config JSON</label>
                <textarea v-model="form.raw_config" rows="6" class="input mt-1 font-mono text-xs" placeholder='{"brackets": [{"up_to": 10000, "rate": 0.05}, {"up_to": null, "rate": 0.08}]}' />
                <p class="mt-1 text-xs text-amber-700">Tiered and bonus rules need a hand-written config. See CommissionPlanRule docblock.</p>
                <p v-if="errors.config" class="mt-1 text-xs text-red-600">{{ errors.config[0] }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.active" type="checkbox" />
                <span>Active</span>
            </label>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('close')">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="submitting">
                    {{ submitting ? 'Saving…' : (rule?.id ? 'Save' : 'Add rule') }}
                </button>
            </div>
        </form>
    </Modal>
</template>
