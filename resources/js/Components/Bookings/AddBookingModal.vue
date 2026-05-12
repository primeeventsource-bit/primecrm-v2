<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import CreateBookingForm from './CreateBookingForm.vue';
import BookingsBulkUpload from './BookingsBulkUpload.vue';

/**
 * Two-tab "Add a booking" modal: single row or CSV/Excel upload.
 *
 * Mirrors the AddListingModal shell — same UX language, different
 * underlying forms. Single path runs against the existing
 * /api/rental-bookings POST endpoint; bulk path runs through the
 * preview/import cycle on /api/rental-bookings/bulk-*.
 */

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'created'): void;
    (e: 'imported'): void;
}>();

type Tab = 'single' | 'bulk';
const tab = ref<Tab>('single');

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
        title="Add a booking"
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

            <CreateBookingForm
                v-if="tab === 'single'"
                @created="emit('created')"
                @cancel="emit('close')"
            />
            <BookingsBulkUpload
                v-else
                @imported="emit('imported')"
                @cancel="emit('close')"
            />
        </div>
    </Modal>
</template>
