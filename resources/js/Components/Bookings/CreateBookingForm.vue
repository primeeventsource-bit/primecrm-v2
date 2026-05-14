<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * "Add a booking" modal body.
 *
 * Pick an existing live listing, capture renter info + final price.
 * Dates and commission auto-prefill from the listing the moment one
 * is selected; operator can override anything. Owner-payout preview
 * runs client-side using the same formula the server applies.
 *
 * Documents (signed agreement, payment proof, guest ID) are STAGED
 * client-side — the booking doesn't exist yet, so there's no id to
 * attach them to. On submit we create the booking, then upload each
 * staged document against the new id before emitting `created`. A
 * document upload failing does NOT roll back the booking.
 */

interface ListingOption {
    id: string;
    status: string;
    check_in_date: string;
    check_out_date: string;
    asking_price: number;
    owner_payout: number | null;
    our_commission_pct: number | null;
    property: {
        id: string;
        resort_name: string;
        resort_brand: string | null;
        location_city: string;
        location_state: string;
    };
    owner: { id: string; name: string };
}

const emit = defineEmits<{ (e: 'created', payload: { id: string }): void; (e: 'cancel'): void }>();

const form = ref({
    listing_id: '',
    renter_name: '',
    renter_email: '',
    renter_phone: '',
    check_in_date: '',
    check_out_date: '',
    total_price: null as number | null,
    commission_pct: null as number | null,
    payment_status: 'pending' as 'pending' | 'deposit_paid' | 'paid_in_full',
    notify_owner: true,
});

const listingQuery = ref('');
const listings = ref<ListingOption[]>([]);
const listingLoading = ref(false);
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

let searchTimer: number | undefined;

async function loadListings(): Promise<void> {
    listingLoading.value = true;
    try {
        const { data } = await axios.get<{ data: ListingOption[] }>(
            '/api/rental-bookings/listings-picker',
            { params: { q: listingQuery.value || undefined } },
        );
        listings.value = data.data;
    } finally {
        listingLoading.value = false;
    }
}

onMounted(loadListings);

watch(listingQuery, () => {
    if (searchTimer !== undefined) clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void loadListings(), 250);
});

// When the listing changes, pre-fill the editable defaults — but only
// if the operator hasn't already typed something. A brand-new pick
// always wins; subsequent re-picks respect existing values.
watch(
    () => form.value.listing_id,
    (newId) => {
        const l = listings.value.find((x) => x.id === newId);
        if (!l) return;
        if (!form.value.check_in_date) form.value.check_in_date = l.check_in_date.split('T')[0];
        if (!form.value.check_out_date) form.value.check_out_date = l.check_out_date.split('T')[0];
        if (form.value.total_price == null) form.value.total_price = l.asking_price;
        if (form.value.commission_pct == null) {
            form.value.commission_pct = l.our_commission_pct ?? 15;
        }
    },
);

const selectedListing = computed<ListingOption | null>(() =>
    listings.value.find((l) => l.id === form.value.listing_id) ?? null,
);

const ownerPayoutPreview = computed<number | null>(() => {
    const price = form.value.total_price;
    const pct = form.value.commission_pct;
    if (price == null || pct == null) return null;
    return Math.round(price * (1 - pct / 100) * 100) / 100;
});

const ourCommissionPreview = computed<number | null>(() => {
    if (form.value.total_price == null || ownerPayoutPreview.value == null) return null;
    return Math.round((form.value.total_price - ownerPayoutPreview.value) * 100) / 100;
});

function fmtMoney(n: number | null): string {
    if (n == null) return '—';
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ──────────────────────────────────────────────────────────────────────
 * Document staging — agreement / payment proof / ID / other.
 *
 * Files are held in memory with a per-file `kind`. Images get a
 * thumbnail preview; PDFs render a generic doc tile. Uploaded after
 * the booking is created.
 * ──────────────────────────────────────────────────────────────────── */
const MAX_DOCS = 20;
type DocKind = 'agreement' | 'payment_proof' | 'id' | 'other';
const DOC_KIND_LABELS: Record<DocKind, string> = {
    agreement: 'Rental agreement',
    payment_proof: 'Payment proof',
    id: 'Guest ID',
    other: 'Other',
};
interface StagedDoc {
    file: File;
    kind: DocKind;
    /** Object URL for image previews; null for PDFs. */
    previewUrl: string | null;
}
const stagedDocs = ref<StagedDoc[]>([]);
const docError = ref<string | null>(null);
const docDragOver = ref(false);
const docInputEl = ref<HTMLInputElement | null>(null);
/** 1..N while uploading post-create; 0 when idle. */
const docUploadIndex = ref(0);

const ACCEPTED_DOC_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

function addDocFiles(files: FileList | File[]): void {
    docError.value = null;
    const incoming = Array.from(files);
    for (const file of incoming) {
        if (stagedDocs.value.length >= MAX_DOCS) {
            docError.value = `Up to ${MAX_DOCS} documents per booking. Extra files were skipped.`;
            break;
        }
        if (!ACCEPTED_DOC_TYPES.includes(file.type)) {
            docError.value = `"${file.name}" isn't a PDF or image and was skipped.`;
            continue;
        }
        if (file.size > 10 * 1024 * 1024) {
            docError.value = `"${file.name}" is over 10MB and was skipped.`;
            continue;
        }
        // Guess the kind from the filename so the operator usually
        // doesn't have to touch the selector.
        const lower = file.name.toLowerCase();
        let kind: DocKind = 'other';
        if (lower.includes('agreement') || lower.includes('contract')) kind = 'agreement';
        else if (lower.includes('payment') || lower.includes('receipt') || lower.includes('invoice')) kind = 'payment_proof';
        else if (lower.includes('id') || lower.includes('license') || lower.includes('passport')) kind = 'id';

        stagedDocs.value.push({
            file,
            kind,
            previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        });
    }
}

function onDocInputChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
        addDocFiles(input.files);
        input.value = '';
    }
}

function onDocDrop(e: DragEvent): void {
    e.preventDefault();
    docDragOver.value = false;
    if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        addDocFiles(e.dataTransfer.files);
    }
}

function removeStagedDoc(idx: number): void {
    const [removed] = stagedDocs.value.splice(idx, 1);
    if (removed?.previewUrl) URL.revokeObjectURL(removed.previewUrl);
}

function fmtBytes(n: number): string {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

onBeforeUnmount(() => {
    for (const d of stagedDocs.value) {
        if (d.previewUrl) URL.revokeObjectURL(d.previewUrl);
    }
});

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    docError.value = null;

    const payload: Record<string, unknown> = { ...form.value };
    for (const k of Object.keys(payload)) {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    }
    // `notify_owner` is a boolean and must survive even when false.
    payload.notify_owner = form.value.notify_owner;

    try {
        const { data } = await axios.post('/api/rental-bookings', payload);
        const bookingId: string = data.data.id;

        // Booking exists now — upload the staged documents against it.
        // A failure here does NOT undo the booking; we count failures
        // so the operator knows to re-attach from the booking later.
        let failed = 0;
        if (stagedDocs.value.length > 0) {
            for (let i = 0; i < stagedDocs.value.length; i++) {
                docUploadIndex.value = i + 1;
                const fd = new FormData();
                fd.append('file', stagedDocs.value[i].file);
                fd.append('kind', stagedDocs.value[i].kind);
                try {
                    await axios.post(`/api/rental-bookings/${bookingId}/documents`, fd, {
                        headers: { 'Content-Type': 'multipart/form-data' },
                    });
                } catch {
                    failed++;
                }
            }
            docUploadIndex.value = 0;
        }

        for (const d of stagedDocs.value) {
            if (d.previewUrl) URL.revokeObjectURL(d.previewUrl);
        }
        stagedDocs.value = [];

        if (failed > 0) {
            docError.value = `${failed} document${failed === 1 ? '' : 's'} failed to upload — `
                + 'the booking was saved; re-attach them from the bookings ledger.';
        }

        emit('created', { id: bookingId });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length && e.response?.data?.message) {
            errors.value._global = [e.response.data.message];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errors._global[0] }}
        </div>

        <!-- Listing picker -->
        <div>
            <label class="label">Listing <span class="text-red-600">*</span></label>
            <input
                v-model="listingQuery"
                type="text"
                placeholder="search owner or resort"
                class="input mt-1 text-sm"
            />
            <div class="mt-2 max-h-48 overflow-y-auto rounded border border-slate-200 bg-white">
                <div v-if="listingLoading" class="px-3 py-2 text-xs text-slate-500">Loading…</div>
                <div v-else-if="listings.length === 0" class="px-3 py-2 text-xs italic text-slate-500">
                    No live listings match. Bookings require an active listing — create one from the Listings page first.
                </div>
                <label
                    v-for="l in listings"
                    :key="l.id"
                    class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    :class="form.listing_id === l.id ? 'bg-amber-50' : ''"
                >
                    <input
                        v-model="form.listing_id"
                        type="radio"
                        :value="l.id"
                        class="mt-1"
                    />
                    <div class="flex-1">
                        <div class="font-medium text-slate-900">
                            {{ l.property.resort_name }}
                            <span class="text-xs font-normal uppercase tracking-wider text-slate-500">· {{ l.status.replace('_', ' ') }}</span>
                        </div>
                        <div class="text-xs text-slate-600">
                            <span v-if="l.property.resort_brand">{{ l.property.resort_brand }} · </span>
                            {{ l.property.location_city }}, {{ l.property.location_state }}
                            · {{ l.check_in_date.split('T')[0] }} → {{ l.check_out_date.split('T')[0] }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Owner: {{ l.owner.name }} · asking {{ fmtMoney(l.asking_price) }}
                        </div>
                    </div>
                </label>
            </div>
            <p v-if="errors.listing_id" class="mt-1 text-xs text-red-600">{{ errors.listing_id[0] }}</p>
        </div>

        <!-- Renter -->
        <fieldset class="grid grid-cols-2 gap-3">
            <div class="col-span-2">
                <label class="label">Renter name <span class="text-red-600">*</span></label>
                <input v-model="form.renter_name" type="text" class="input mt-1" maxlength="200" required />
                <p v-if="errors.renter_name" class="mt-1 text-xs text-red-600">{{ errors.renter_name[0] }}</p>
            </div>
            <div>
                <label class="label">Renter email</label>
                <input v-model="form.renter_email" type="email" class="input mt-1" maxlength="200" />
                <p v-if="errors.renter_email" class="mt-1 text-xs text-red-600">{{ errors.renter_email[0] }}</p>
            </div>
            <div>
                <label class="label">Renter phone</label>
                <input v-model="form.renter_phone" type="tel" class="input mt-1" maxlength="30" />
                <p v-if="errors.renter_phone" class="mt-1 text-xs text-red-600">{{ errors.renter_phone[0] }}</p>
            </div>
        </fieldset>

        <!-- Dates -->
        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">Check-in</label>
                <input v-model="form.check_in_date" type="date" class="input mt-1" />
                <p v-if="errors.check_in_date" class="mt-1 text-xs text-red-600">{{ errors.check_in_date[0] }}</p>
            </div>
            <div>
                <label class="label">Check-out</label>
                <input v-model="form.check_out_date" type="date" class="input mt-1" />
                <p v-if="errors.check_out_date" class="mt-1 text-xs text-red-600">{{ errors.check_out_date[0] }}</p>
            </div>
        </fieldset>

        <!-- Pricing -->
        <fieldset class="grid grid-cols-3 gap-3">
            <div>
                <label class="label">Total price ($) <span class="text-red-600">*</span></label>
                <input v-model.number="form.total_price" type="number" min="0" step="50" class="input mt-1" required />
                <p v-if="errors.total_price" class="mt-1 text-xs text-red-600">{{ errors.total_price[0] }}</p>
            </div>
            <div>
                <label class="label">Commission %</label>
                <input v-model.number="form.commission_pct" type="number" min="0" max="100" step="0.5" class="input mt-1" />
                <p v-if="errors.commission_pct" class="mt-1 text-xs text-red-600">{{ errors.commission_pct[0] }}</p>
            </div>
            <div>
                <label class="label">Payment</label>
                <select v-model="form.payment_status" class="input mt-1">
                    <option value="pending">Pending</option>
                    <option value="deposit_paid">Deposit paid</option>
                    <option value="paid_in_full">Paid in full</option>
                </select>
            </div>
        </fieldset>

        <div v-if="ownerPayoutPreview !== null" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            Owner gets <span class="font-semibold text-slate-900">{{ fmtMoney(ownerPayoutPreview) }}</span>
            · we earn <span class="font-semibold text-slate-900">{{ fmtMoney(ourCommissionPreview) }}</span>
        </div>

        <!-- Documents — staged here, uploaded right after the booking is created -->
        <div>
            <label class="label">
                Documents
                <span class="text-xs font-normal text-slate-500">
                    ({{ stagedDocs.length }}/{{ MAX_DOCS }} · PDF or image, 10MB each — agreement, payment proof, guest ID)
                </span>
            </label>

            <div v-if="stagedDocs.length > 0" class="mt-1 space-y-2">
                <div
                    v-for="(d, idx) in stagedDocs"
                    :key="idx"
                    class="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2"
                >
                    <!-- Image thumbnail or PDF glyph -->
                    <div class="h-10 w-10 shrink-0 overflow-hidden rounded bg-slate-100 flex items-center justify-center">
                        <img v-if="d.previewUrl" :src="d.previewUrl" :alt="d.file.name" class="h-full w-full object-cover" />
                        <span v-else class="text-[10px] font-mono font-bold text-slate-500">PDF</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm text-slate-800">{{ d.file.name }}</div>
                        <div class="text-xs text-slate-400">{{ fmtBytes(d.file.size) }}</div>
                    </div>
                    <select v-model="d.kind" class="input text-xs py-1 w-36 shrink-0">
                        <option v-for="(label, value) in DOC_KIND_LABELS" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <button
                        type="button"
                        class="shrink-0 text-xs text-red-600 hover:underline"
                        @click="removeStagedDoc(idx)"
                    >Remove</button>
                </div>
            </div>

            <label
                v-if="stagedDocs.length < MAX_DOCS"
                class="mt-2 flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed py-4 text-center text-xs transition-colors"
                :class="docDragOver
                    ? 'border-floor-accent bg-floor-accent/[0.06] text-slate-700'
                    : 'border-slate-300 bg-slate-50 text-slate-500 hover:border-floor-accent/50'"
                @dragover.prevent="docDragOver = true"
                @dragenter.prevent="docDragOver = true"
                @dragleave.prevent="docDragOver = false"
                @drop="onDocDrop"
            >
                <input
                    ref="docInputEl"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png,image/webp"
                    multiple
                    class="hidden"
                    :disabled="submitting"
                    @change="onDocInputChange"
                />
                <span class="text-lg text-slate-400">+</span>
                <span class="mt-0.5">Add documents — drop or click</span>
            </label>

            <p v-if="docError" class="mt-1 text-xs text-amber-700">{{ docError }}</p>
            <p v-if="submitting && docUploadIndex > 0" class="mt-1 text-xs text-slate-500">
                Uploading document {{ docUploadIndex }} of {{ stagedDocs.length }}…
            </p>
        </div>

        <!-- Owner notification -->
        <div class="flex items-start gap-2">
            <input id="notify_owner" v-model="form.notify_owner" type="checkbox" class="mt-1" />
            <label for="notify_owner" class="text-sm text-slate-700">
                Notify owner
                <span class="block text-xs text-slate-500">
                    Drops a system note on the owner's profile. Uncheck only if you've already told them out-of-band.
                </span>
            </label>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting || !form.listing_id">
                {{ submitting
                    ? (docUploadIndex > 0 ? 'Uploading documents…' : 'Saving…')
                    : (stagedDocs.length > 0 ? `Confirm booking + ${stagedDocs.length} doc${stagedDocs.length === 1 ? '' : 's'}` : 'Confirm booking') }}
            </button>
        </div>
    </form>
</template>
