<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import AddInventorySingleForm from './AddInventorySingleForm.vue';
import AddInventoryBulkUpload from './AddInventoryBulkUpload.vue';

const props = defineProps<{ open: boolean }>();

/**
 * "Add inventory" modal — two-tab shell.
 *
 * Tab 1 (Single row) handles the one-off case: an agent gets a
 * partner on the phone and needs to add a week right now.
 * Tab 2 (Upload) handles the partner-feed case: a CSV/XLSX dropped
 * in from Redweek / Marriott / etc.
 *
 * The shell stays neutral; each tab's component owns its own state,
 * validation, and submit lifecycle. The shell only re-emits
 * `created` (single) / `imported` (bulk) up to the page so the
 * inventory list can refresh.
 */

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'created'): void;
    (e: 'imported'): void;
}>();

type Tab = 'single' | 'bulk';
const tab = ref<Tab>('single');

// Reset to "single" each time the modal opens so the operator
// doesn't land on a half-completed bulk preview from last time.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) tab.value = 'single';
    },
);
</script>

<template>
    <Modal
        :open="open"
        title="Add inventory"
        max-width="max-w-3xl"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <!-- Tab strip -->
            <div class="flex gap-1 border-b border-slate-200">
                <button
                    type="button"
                    class="px-4 py-2 text-sm border-b-2 -mb-px transition-colors"
                    :class="tab === 'single'
                        ? 'border-floor-accent text-slate-900 font-medium'
                        : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="tab = 'single'"
                >Single row</button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm border-b-2 -mb-px transition-colors"
                    :class="tab === 'bulk'
                        ? 'border-floor-accent text-slate-900 font-medium'
                        : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="tab = 'bulk'"
                >Upload CSV / Excel</button>
            </div>

            <AddInventorySingleForm
                v-if="tab === 'single'"
                @created="emit('created')"
                @cancel="emit('close')"
            />
            <AddInventoryBulkUpload
                v-else
                @imported="emit('imported')"
                @cancel="emit('close')"
            />
        </div>
    </Modal>
</template>
