<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PlanFormModal from '@/Components/Commission/PlanFormModal.vue';
import RuleFormModal from '@/Components/Commission/RuleFormModal.vue';

type RuleType = 'percentage' | 'flat' | 'override' | 'tiered' | 'bonus';
type Role = 'closer' | 'fronter' | 'supervisor' | 'qa' | 'override';

interface Rule {
    id: string;
    role: Role;
    rule_type: RuleType;
    trigger_event: string;
    config: Record<string, unknown>;
    priority: number;
    active: boolean;
}

interface Plan {
    id: string;
    name: string;
    description: string | null;
    active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    rules: Rule[];
}

const plans = ref<Plan[]>([]);
const loading = ref(false);
const expanded = ref<Record<string, boolean>>({});

const planModalOpen = ref(false);
const editingPlan = ref<Plan | null>(null);

const ruleModalOpen = ref(false);
const ruleModalPlanId = ref<string>('');
const editingRule = ref<Rule | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: Plan[] }>('/api/commission/plans', {
            params: { with_rules: true },
        });
        plans.value = data.data;
    } finally {
        loading.value = false;
    }
}

function openNewPlan(): void {
    editingPlan.value = null;
    planModalOpen.value = true;
}

function openEditPlan(p: Plan): void {
    editingPlan.value = p;
    planModalOpen.value = true;
}

async function archivePlan(p: Plan): Promise<void> {
    if (!window.confirm(`Archive "${p.name}"? Existing assignments will keep their plan reference, but the plan won't appear in new pickers.`)) {
        return;
    }
    await axios.delete(`/api/commission/plans/${p.id}`);
    await load();
}

function openNewRule(planId: string): void {
    ruleModalPlanId.value = planId;
    editingRule.value = null;
    ruleModalOpen.value = true;
}

function openEditRule(planId: string, r: Rule): void {
    ruleModalPlanId.value = planId;
    editingRule.value = r;
    ruleModalOpen.value = true;
}

async function disableRule(planId: string, r: Rule): Promise<void> {
    if (!window.confirm('Disable this rule? It will stop applying to new events. Past calculations are preserved.')) {
        return;
    }
    await axios.delete(`/api/commission/plans/${planId}/rules/${r.id}`);
    await load();
}

function summarizeConfig(r: Rule): string {
    const c = r.config ?? {};
    if (r.rule_type === 'percentage' && c.rate != null) {
        return `${(Number(c.rate) * 100).toFixed(2).replace(/\.00$/, '')}% of ${c.base_field ?? 'amount'}`;
    }
    if (r.rule_type === 'flat' && c.amount != null) {
        return `$${Number(c.amount).toFixed(2)} flat`;
    }
    if (r.rule_type === 'override' && c.override_rate != null) {
        return `${(Number(c.override_rate) * 100).toFixed(2).replace(/\.00$/, '')}% override`;
    }
    return JSON.stringify(c);
}

async function onPlanSaved(payload: { newPlanId: string | null }): Promise<void> {
    planModalOpen.value = false;
    await load();

    // For a brand-new plan, chain straight into "add your first rule".
    // A plan without rules pays out nothing — surfacing the rule form
    // here means the operator can't accidentally end the flow with a
    // half-configured plan.
    if (payload.newPlanId !== null) {
        expanded.value = { ...expanded.value, [payload.newPlanId]: true };
        ruleModalPlanId.value = payload.newPlanId;
        editingRule.value = null;
        ruleModalOpen.value = true;
    }
}

function onRuleSaved(): void {
    ruleModalOpen.value = false;
    void load();
}

onMounted(load);
</script>

<template>
    <AppLayout title="Commission Plans">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Commission plans</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Plans + rules that govern what agents earn. Assign plans to agents from the Sales Agents page.
                    </p>
                </div>
                <button class="btn-primary" @click="openNewPlan">+ New plan</button>
            </div>

            <PlanFormModal :open="planModalOpen" :plan="editingPlan" @saved="onPlanSaved" @close="planModalOpen = false" />
            <RuleFormModal
                :open="ruleModalOpen"
                :plan-id="ruleModalPlanId"
                :rule="editingRule"
                @saved="onRuleSaved"
                @close="ruleModalOpen = false"
            />

            <div v-if="loading && plans.length === 0" class="panel p-12 text-center text-sm text-slate-500">
                Loading plans…
            </div>
            <div v-else-if="plans.length === 0" class="panel p-12 text-center text-sm text-slate-500">
                No commission plans yet. Click <b>+ New plan</b> to create one — or run the seeder to backfill defaults
                (<code>php artisan db:seed --class=CommissionPlansSeeder</code>).
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="p in plans"
                    :key="p.id"
                    class="panel overflow-hidden"
                    :class="p.rules.length === 0 ? 'ring-1 ring-amber-300/40' : ''"
                >
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <button
                            class="flex items-center gap-2 text-left"
                            @click="expanded[p.id] = !expanded[p.id]"
                        >
                            <span class="text-slate-400 text-xs font-mono">{{ expanded[p.id] ? '▾' : '▸' }}</span>
                            <span class="font-medium text-slate-900">{{ p.name }}</span>
                            <span
                                class="pill"
                                :class="p.active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'"
                            >
                                {{ p.active ? 'active' : 'inactive' }}
                            </span>
                            <!-- Zero-rule plans pay nothing — surface a loud badge so the
                                 operator can't mistake an empty plan for a working one. -->
                            <span
                                v-if="p.rules.length === 0"
                                class="pill bg-amber-100 text-amber-800"
                                title="This plan has no rules — no commission will be paid until at least one rule is added."
                            >
                                ⚠ no rules — pays nothing
                            </span>
                            <span class="text-xs text-slate-500">
                                {{ p.effective_from }}<span v-if="p.effective_to"> → {{ p.effective_to }}</span>
                            </span>
                            <span
                                v-if="p.rules.length > 0"
                                class="text-xs text-slate-400"
                            >· {{ p.rules.length }} rule{{ p.rules.length === 1 ? '' : 's' }}</span>
                        </button>
                        <div class="flex items-center gap-2">
                            <button class="btn-ghost text-xs text-slate-600 hover:bg-slate-100" @click="openEditPlan(p)">Edit</button>
                            <button class="btn-ghost text-xs text-red-600 hover:bg-red-50" @click="archivePlan(p)">Archive</button>
                        </div>
                    </div>

                    <div v-if="p.description" class="px-4 pb-2 text-sm text-slate-600">{{ p.description }}</div>

                    <div v-if="expanded[p.id]" class="border-t border-slate-100 bg-slate-50/50 px-4 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rules</h3>
                            <button class="btn-ghost text-xs text-slate-700 hover:bg-slate-100" @click="openNewRule(p.id)">+ Add rule</button>
                        </div>

                        <table v-if="p.rules.length > 0" class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-slate-400">
                                    <th class="py-1 pr-3">Role</th>
                                    <th class="py-1 pr-3">Type</th>
                                    <th class="py-1 pr-3">Trigger</th>
                                    <th class="py-1 pr-3">Config</th>
                                    <th class="py-1 pr-3">Pri.</th>
                                    <th class="py-1 pr-3"></th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="r in p.rules" :key="r.id" :class="r.active ? '' : 'opacity-50'">
                                    <td class="py-1 pr-3 text-slate-700">{{ r.role }}</td>
                                    <td class="py-1 pr-3 text-slate-700">{{ r.rule_type }}</td>
                                    <td class="py-1 pr-3 font-mono text-xs text-slate-600">{{ r.trigger_event }}</td>
                                    <td class="py-1 pr-3 text-slate-700">{{ summarizeConfig(r) }}</td>
                                    <td class="py-1 pr-3 text-slate-500">{{ r.priority }}</td>
                                    <td class="py-1 pr-3 text-xs text-slate-400">{{ r.active ? '' : 'disabled' }}</td>
                                    <td class="py-1 text-right">
                                        <button class="text-xs text-slate-600 hover:underline mr-3" @click="openEditRule(p.id, r)">Edit</button>
                                        <button v-if="r.active" class="text-xs text-red-600 hover:underline" @click="disableRule(p.id, r)">Disable</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div
                            v-else
                            class="my-2 flex items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-900"
                        >
                            <div>
                                <div class="font-medium">No rules on this plan yet.</div>
                                <p class="mt-0.5 text-xs text-amber-800">
                                    A plan without rules pays nothing — agents assigned to it will earn $0 on every event.
                                    Add at least one rule (a percentage, flat amount, or tiered schedule) before assigning agents.
                                </p>
                            </div>
                            <button class="btn-primary text-xs whitespace-nowrap" @click="openNewRule(p.id)">
                                + Add first rule
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
