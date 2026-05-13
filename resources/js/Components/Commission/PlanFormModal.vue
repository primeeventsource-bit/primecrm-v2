<script setup lang="ts">
import { ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

interface PlanInput {
    id?: string;
    name: string;
    description: string | null;
    active: boolean;
    effective_from: string | null;
    effective_to: string | null;
}

const props = defineProps<{ open: boolean; plan: PlanInput | null }>();
const emit = defineEmits<{
    /**
     * Plan saved. `newPlanId` is set ONLY on create (not edit) so the
     * parent can chain into "add your first rule" without re-querying
     * the plans list to find the row it just created.
     */
    (e: 'saved', payload: { newPlanId: string | null }): void;
    (e: 'close'): void;
}>();

const form = ref({
    name: '',
    description: '',
    active: true,
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
});
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

watch(
    () => [props.open, props.plan],
    () => {
        if (!props.open) return;
        errors.value = {};
        if (props.plan) {
            form.value = {
                name: props.plan.name,
                description: props.plan.description ?? '',
                active: props.plan.active,
                effective_from: props.plan.effective_from ?? new Date().toISOString().slice(0, 10),
                effective_to: props.plan.effective_to ?? '',
            };
        } else {
            form.value = {
                name: '',
                description: '',
                active: true,
                effective_from: new Date().toISOString().slice(0, 10),
                effective_to: '',
            };
        }
    },
    { immediate: true },
);

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload: Record<string, unknown> = {
        name: form.value.name,
        description: form.value.description || null,
        active: form.value.active,
        effective_from: form.value.effective_from,
        effective_to: form.value.effective_to || null,
    };

    try {
        if (props.plan?.id) {
            await axios.patch(`/api/commission/plans/${props.plan.id}`, payload);
            emit('saved', { newPlanId: null });
        } else {
            // PlanController::store returns the serialized plan
            // directly (no `data` envelope) — see private serialize().
            const { data } = await axios.post<{ id: string }>(
                '/api/commission/plans',
                payload,
            );
            emit('saved', { newPlanId: data.id });
        }
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
    <Modal :open="open" :title="plan?.id ? 'Edit plan' : 'New commission plan'" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ errors._global[0] }}
            </div>

            <div>
                <label class="label">Name <span class="text-red-600">*</span></label>
                <input v-model="form.name" type="text" class="input mt-1" maxlength="160" required />
                <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
            </div>

            <div>
                <label class="label">Description</label>
                <textarea v-model="form.description" rows="2" maxlength="500" class="input mt-1" />
                <p v-if="errors.description" class="mt-1 text-xs text-red-600">{{ errors.description[0] }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Effective from <span class="text-red-600">*</span></label>
                    <input v-model="form.effective_from" type="date" class="input mt-1" required />
                    <p v-if="errors.effective_from" class="mt-1 text-xs text-red-600">{{ errors.effective_from[0] }}</p>
                </div>
                <div>
                    <label class="label">Effective to</label>
                    <input v-model="form.effective_to" type="date" class="input mt-1" />
                    <p class="mt-1 text-xs text-slate-500">Blank = open-ended.</p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.active" type="checkbox" />
                <span>Active</span>
            </label>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('close')">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="submitting">
                    {{ submitting ? 'Saving…' : (plan?.id ? 'Save' : 'Create plan') }}
                </button>
            </div>
        </form>
    </Modal>
</template>
