<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

/**
 * Manage documents on an EXISTING booking.
 *
 * Distinct from the staging flow in CreateBookingForm: the booking
 * already has an id here, so every upload / delete hits the API
 * immediately. This is the "I forgot to attach the signed agreement"
 * fix — opened from the bookings ledger's docs column.
 *
 * Emits `updated` with the fresh documents array after every change
 * so the ledger row's count stays in sync without a full reload.
 */

type DocKind = 'agreement' | 'payment_proof' | 'id' | 'other';

interface BookingDocument {
    url: string;
    name: string;
    kind: DocKind;
    size: number;
    uploaded_at: string;
}

const DOC_KIND_LABELS: Record<DocKind, string> = {
    agreement: 'Rental agreement',
    payment_proof: 'Payment proof',
    id: 'Guest ID',
    other: 'Other',
};

const props = defineProps<{
    open: boolean;
    bookingId: string | null;
    bookingLabel: string;
    initialDocuments: BookingDocument[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'updated', documents: BookingDocument[]): void;
}>();

const docs = ref<BookingDocument[]>([]);
const uploadKind = ref<DocKind>('agreement');
const uploading = ref(false);
const errorMsg = ref<string | null>(null);
const dragOver = ref(false);
const fileInputEl = ref<HTMLInputElement | null>(null);

const MAX_DOCS = 20;
const ACCEPTED = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

// Reset local state each time the modal opens against a (possibly
// different) booking — the parent passes the row's current docs in.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            docs.value = [...(props.initialDocuments ?? [])];
            uploadKind.value = 'agreement';
            errorMsg.value = null;
            dragOver.value = false;
        }
    },
    { immediate: true },
);

const atLimit = computed(() => docs.value.length >= MAX_DOCS);

function fmtBytes(n: number): string {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

function fmtDate(iso: string): string {
    return iso.split('T')[0];
}

function isImage(name: string): boolean {
    return /\.(jpe?g|png|webp)$/i.test(name);
}

async function uploadFiles(files: FileList | File[]): Promise<void> {
    if (!props.bookingId) return;
    errorMsg.value = null;

    for (const file of Array.from(files)) {
        if (docs.value.length >= MAX_DOCS) {
            errorMsg.value = `Up to ${MAX_DOCS} documents per booking. Extra files were skipped.`;
            break;
        }
        if (!ACCEPTED.includes(file.type)) {
            errorMsg.value = `"${file.name}" isn't a PDF or image and was skipped.`;
            continue;
        }
        if (file.size > 10 * 1024 * 1024) {
            errorMsg.value = `"${file.name}" is over 10MB and was skipped.`;
            continue;
        }

        uploading.value = true;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('kind', uploadKind.value);
        try {
            const { data } = await axios.post<{ documents: BookingDocument[] }>(
                `/api/rental-bookings/${props.bookingId}/documents`,
                fd,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            docs.value = data.documents;
            emit('updated', data.documents);
        } catch (e: unknown) {
            const resp = (e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response;
            errorMsg.value = resp?.data?.errors?.file?.[0]
                ?? resp?.data?.message
                ?? `Could not upload "${file.name}".`;
        }
    }
    uploading.value = false;
}

function onInputChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
        void uploadFiles(input.files);
        input.value = '';
    }
}

function onDrop(e: DragEvent): void {
    e.preventDefault();
    dragOver.value = false;
    if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        void uploadFiles(e.dataTransfer.files);
    }
}

async function removeDocument(doc: BookingDocument): Promise<void> {
    if (!props.bookingId) return;
    if (!window.confirm(`Delete "${doc.name}"? This can't be undone.`)) return;
    errorMsg.value = null;
    try {
        const { data } = await axios.delete<{ documents: BookingDocument[] }>(
            `/api/rental-bookings/${props.bookingId}/documents`,
            { data: { url: doc.url } },
        );
        docs.value = data.documents;
        emit('updated', data.documents);
    } catch (e: unknown) {
        const resp = (e as { response?: { data?: { message?: string } } }).response;
        errorMsg.value = resp?.data?.message ?? 'Could not delete the document.';
    }
}
</script>

<template>
    <Modal :open="open" title="Booking documents" max-width="max-w-2xl" @close="emit('close')">
        <div class="space-y-4">
            <p class="text-xs text-slate-500">{{ bookingLabel }}</p>

            <div v-if="errorMsg" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {{ errorMsg }}
            </div>

            <!-- Existing documents -->
            <div v-if="docs.length > 0" class="space-y-2">
                <div
                    v-for="doc in docs"
                    :key="doc.url"
                    class="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2"
                >
                    <div class="h-10 w-10 shrink-0 overflow-hidden rounded bg-slate-100 flex items-center justify-center">
                        <img v-if="isImage(doc.name)" :src="doc.url" :alt="doc.name" class="h-full w-full object-cover" />
                        <span v-else class="text-[10px] font-mono font-bold text-slate-500">PDF</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <a
                            :href="doc.url"
                            target="_blank"
                            rel="noopener"
                            class="block truncate text-sm text-slate-800 hover:text-floor-accent hover:underline"
                        >{{ doc.name }}</a>
                        <div class="flex items-center gap-2 text-xs text-slate-400">
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono uppercase tracking-wider text-slate-600">
                                {{ DOC_KIND_LABELS[doc.kind] ?? doc.kind }}
                            </span>
                            <span>{{ fmtBytes(doc.size) }}</span>
                            <span>· {{ fmtDate(doc.uploaded_at) }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 text-xs text-red-600 hover:underline"
                        @click="removeDocument(doc)"
                    >Delete</button>
                </div>
            </div>
            <p v-else class="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
                No documents on this booking yet.
            </p>

            <!-- Upload -->
            <div v-if="!atLimit" class="rounded-md border border-slate-200 p-3">
                <div class="flex items-center gap-2 mb-2">
                    <label class="label shrink-0">Upload as</label>
                    <select v-model="uploadKind" class="input text-sm py-1 w-44" :disabled="uploading">
                        <option v-for="(label, value) in DOC_KIND_LABELS" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>
                <label
                    class="flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed py-4 text-center text-xs transition-colors"
                    :class="dragOver
                        ? 'border-floor-accent bg-floor-accent/[0.06] text-slate-700'
                        : 'border-slate-300 bg-slate-50 text-slate-500 hover:border-floor-accent/50'"
                    @dragover.prevent="dragOver = true"
                    @dragenter.prevent="dragOver = true"
                    @dragleave.prevent="dragOver = false"
                    @drop="onDrop"
                >
                    <input
                        ref="fileInputEl"
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        multiple
                        class="hidden"
                        :disabled="uploading"
                        @change="onInputChange"
                    />
                    <span class="text-lg text-slate-400">+</span>
                    <span class="mt-0.5">{{ uploading ? 'Uploading…' : 'Add document — drop or click' }}</span>
                    <span class="text-[10px] text-slate-400">PDF or image, 10MB each</span>
                </label>
            </div>
            <p v-else class="text-xs text-slate-500">
                Document limit reached ({{ MAX_DOCS }}). Delete one to add another.
            </p>

            <div class="flex justify-end border-t border-slate-200 pt-2">
                <button type="button" class="btn-primary" @click="emit('close')">Done</button>
            </div>
        </div>
    </Modal>
</template>
