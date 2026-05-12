<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import CreateListingForm from './CreateListingForm.vue';
import ListingsBulkUpload from './ListingsBulkUpload.vue';

/**
 * Two-tab "Add a listing" modal: single row or CSV/Excel upload.
 *
 * The shell re-emits each tab's success event (`created` / `imported`)
 * so the Index page can refresh once and forget the difference.
 */

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'created', payload: { id: string }): void;
    (e: 'imported'): void;
}>();

type Tab = 'single' | 'bulk';
const tab = ref<Tab>('single');

// Each open resets to "single" so the operator doesn't see a stale
// bulk preview when re-opening.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) tab.value = 'single';
    },
);

function onCreated(payload: { id: string }): void {
    emit('created', payload);
}
</script>

<template>
    <Modal
        :open="open"
        title="Add a listing"
        max-width="max-w-3xl"
        @close="emit('close')"
    >
        <div class="space-y-4">
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

            <CreateListingForm
                v-if="tab === 'single'"
                @created="onCreated"
                @cancel="emit('close')"
            />
            <ListingsBulkUpload
                v-else
                @imported="emit('imported')"
                @cancel="emit('close')"
            />
        </div>
    </Modal>
</template>
