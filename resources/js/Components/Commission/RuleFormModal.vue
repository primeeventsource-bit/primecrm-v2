<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import { buildRuleConfig, type RuleType, type TieredBracket } from './ruleConfig';

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

function emptyBracket(): TieredBracket {
    return { up_to: null, rate: 0 };
}

const form = ref({
    role: 'closer' as Role,
    rule_type: 'percentage' as RuleType,
    trigger_event: 'payment.cleared',
    priority: 10,
    active: true,

    // Per-type fields (flattened for the form, repacked into `config` on submit)
    pct_rate: '8' as string,
    pct_base_field: 'amount',
    flat_amount: '' as string,
    override_rate: '1' as string,

    // Structured tiered editor
    tiered_base_field: 'amount',
    tiered_marginal: false,
    tiered_brackets: [
        { up_to: 5000, rate: 0.05 },
        { up_to: null, rate: 0.08 }, // top tier
    ] as TieredBracket[],

    // Bonus fallback — raw JSON (engine doesn't process bonus yet)
    raw_config: '' as string,
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
            const cfg = (r.config ?? {}) as Record<string, unknown>;
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

                tiered_base_field: (cfg.base_field as string) ?? 'amount',
                tiered_marginal: Boolean(cfg.marginal),
                tiered_brackets: Array.isArray(cfg.brackets) && cfg.brackets.length > 0
                    ? (cfg.brackets as TieredBracket[]).map((b) => ({
                        up_to: b.up_to == null ? null : Number(b.up_to),
                        rate: Number(b.rate),
                    }))
                    : [emptyBracket()],

                raw_config: r.rule_type === 'bonus' ? JSON.stringify(cfg, null, 2) : '',
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
                tiered_base_field: 'amount',
                tiered_marginal: false,
                tiered_brackets: [
                    { up_to: 5000, rate: 0.05 },
                    { up_to: null, rate: 0.08 },
                ],
                raw_config: '',
            };
        }
    },
    { immediate: true },
);

const showPercentageFields = computed(() => form.value.rule_type === 'percentage');
const showFlatFields = computed(() => form.value.rule_type === 'flat');
const showOverrideFields = computed(() => form.value.rule_type === 'override');
const showTieredFields = computed(() => form.value.rule_type === 'tiered');
const showBonusFields = computed(() => form.value.rule_type === 'bonus');

function addBracket(): void {
    form.value.tiered_brackets.push(emptyBracket());
}

function removeBracket(idx: number): void {
    form.value.tiered_brackets.splice(idx, 1);
    if (form.value.tiered_brackets.length === 0) addBracket();
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const result = buildRuleConfig(form.value);
    if (result.config === null) {
        errors.value.config = [result.error ?? 'Invalid config.'];
        submitting.value = false;
        return;
    }

    const payload = {
        role: form.value.role,
        rule_type: form.value.rule_type,
        trigger_event: form.value.trigger_event,
        config: result.config,
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
                        <option value="tiered">Tiered</option>
                        <option value="bonus">Bonus (placeholder)</option>
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

            <div v-if="showTieredFields" class="rounded-md border border-slate-200 p-3">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="label">Base field</label>
                        <input v-model="form.tiered_base_field" type="text" class="input mt-1" />
                        <p class="mt-1 text-xs text-slate-500">Payload key the tiers apply to. Usually <code>amount</code>.</p>
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input v-model="form.tiered_marginal" type="checkbox" />
                            <span>Marginal mode</span>
                        </label>
                    </div>
                </div>

                <p class="text-xs text-slate-500 mb-2">
                    <b>Non-marginal</b> (default): the matching tier's rate applies to the whole base.
                    <b>Marginal</b>: each portion of the base is paid at its tier's rate (true marginal). The top tier
                    leaves <i>Up to</i> blank for "unbounded".
                </p>

                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-400">
                            <th class="py-1 pr-3">Up to ($)</th>
                            <th class="py-1 pr-3">Rate (%)</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(b, i) in form.tiered_brackets" :key="i" class="align-middle">
                            <td class="py-1 pr-3">
                                <input
                                    :value="b.up_to ?? ''"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="input w-32"
                                    placeholder="(top tier)"
                                    @input="(e) => {
                                        const v = (e.target as HTMLInputElement).value;
                                        form.tiered_brackets[i].up_to = v === '' ? null : Number(v);
                                    }"
                                />
                            </td>
                            <td class="py-1 pr-3">
                                <div class="flex items-stretch w-32">
                                    <input
                                        :value="b.rate != null ? (b.rate * 100) : ''"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        class="input rounded-r-none"
                                        @input="(e) => {
                                            const v = (e.target as HTMLInputElement).value;
                                            form.tiered_brackets[i].rate = v === '' ? 0 : Number(v) / 100;
                                        }"
                                    />
                                    <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-2 text-xs text-slate-500">%</span>
                                </div>
                            </td>
                            <td class="py-1">
                                <button type="button" class="text-xs text-red-600 hover:underline" @click="removeBracket(i)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="btn-ghost mt-2 text-xs text-slate-700 hover:bg-slate-100" @click="addBracket">+ Add bracket</button>
                <p v-if="errors.config" class="mt-1 text-xs text-red-600">{{ errors.config[0] }}</p>
            </div>

            <div v-if="showBonusFields" class="rounded-md border border-amber-200 bg-amber-50 p-3">
                <label class="label">Config JSON</label>
                <textarea v-model="form.raw_config" rows="6" class="input mt-1 font-mono text-xs" placeholder='{"threshold": 10, "amount": 500}' />
                <p class="mt-1 text-xs text-amber-700">
                    Bonus rules are a placeholder — they aren't applied by the engine yet. You can stage a config now,
                    but no payouts will fire until the period-rollup handler ships.
                </p>
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
