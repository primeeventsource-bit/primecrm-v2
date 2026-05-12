<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';

/**
 * Bulk-upload tab for bookings — file pick → preview → confirm.
 *
 * Bookings are strict: the row must match an existing listing or
 * it's flagged invalid. No new-entity approvals. The operator can
 * still review per-row status and toggle owner notification before
 * committing the import.
 */

interface PreviewRow {
    row_num: number;
    valid: boolean;
    errors: string[];
    warnings: string[];
    data: Record<string, unknown> & {
        listing_id?: string | null;
        owner_email?: string | null;
        owner_phone?: string | null;
        resort_name?: string;
        listing_check_in_date?: string | null;
        renter_name?: string;
        renter_email?: string | null;
        check_in_date?: string | null;
        check_out_date?: string | null;
        total_price?: number | null;
        payment_status?: string;
    };
    matched_listing_id: string | null;
}

interface PreviewResponse {
    preview_token: string;
    summary: {
        total_rows: number;
        valid_rows: number;
        invalid_rows: number;
        fatal_error?: string;
    };
    rows: PreviewRow[];
}

interface ImportResponse {
    message: string;
    data: {
        bookings_created: number;
        rows_skipped: number;
        owners_notified: number;
        skip_reasons: Array<{ row_num: number; reason: string }>;
    };
}

const emit = defineEmits<{ (e: 'imported'): void; (e: 'cancel'): void }>();

const file = ref<File | null>(null);
const fileError = ref<string | null>(null);
const uploading = ref(false);
const importing = ref(false);
const notifyOwners = ref(false);

const preview = ref<PreviewResponse | null>(null);
const importResult = ref<ImportResponse | null>(null);
const showRowDetail = ref(true);

function onFileChange(e: Event): void {
    const t = e.target as HTMLInputElement;
    file.value = t.files?.[0] ?? null;
    fileError.value = null;
    preview.value = null;
    importResult.value = null;
}

async function uploadForPreview(): Promise<void> {
    if (!file.value) {
        fileError.value = 'Pick a file first.';
        return;
    }
    uploading.value = true;
    fileError.value = null;
    try {
        const formData = new FormData();
        formData.append('file', file.value);
        const { data } = await axios.post<PreviewResponse>(
            '/api/rental-bookings/bulk-preview',
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );
        preview.value = data;
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        fileError.value = e.response?.data?.errors?.file?.[0]
            ?? e.response?.data?.message
            ?? 'Upload failed.';
    } finally {
        uploading.value = false;
    }
}

const importDisabled = computed(() => {
    if (!preview.value) return true;
    return preview.value.summary.valid_rows === 0;
});

async function confirmImport(): Promise<void> {
    if (!preview.value) return;
    importing.value = true;
    try {
        const { data } = await axios.post<ImportResponse>('/api/rental-bookings/bulk-import', {
            preview_token: preview.value.preview_token,
            notify_owners: notifyOwners.value,
        });
        importResult.value = data;
        emit('imported');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        fileError.value = e.response?.data?.message ?? 'Import failed.';
    } finally {
        importing.value = false;
    }
}

function startOver(): void {
    file.value = null;
    preview.value = null;
    importResult.value = null;
    fileError.value = null;
    notifyOwners.value = false;
}
</script>

<template>
    <div class="space-y-4">
        <!-- Step 1: file pick -->
        <section v-if="!preview && !importResult" class="space-y-3">
            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <div class="font-medium text-slate-800">CSV / Excel format</div>
                <div class="mt-1">
                    Identify each booking's listing either by <code>listing_id</code>
                    OR by <code>owner_email</code>/<code>owner_phone</code> +
                    <code>resort_name</code> + <code>listing_check_in_date</code>.
                </div>
                <div class="mt-1">
                    Required: renter_name, total_price.
                    Optional: renter_email, renter_phone, booking-specific
                    check_in_date/check_out_date, commission_pct, payment_status.
                </div>
                <div class="mt-1">
                    Listings must already exist — rows that don't match are
                    skipped (no auto-create). Create listings via the Listings
                    bulk import first if needed.
                </div>
                <a
                    href="/api/rental-bookings/template.csv"
                    class="mt-2 inline-block text-floor-accent hover:underline"
                    download
                >
                    ↓ Download CSV template
                </a>
            </div>

            <div>
                <label class="label">Bookings file <span class="text-red-600">*</span></label>
                <input
                    type="file"
                    accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    class="mt-1 block w-full text-sm text-slate-700"
                    @change="onFileChange"
                />
                <p v-if="fileError" class="mt-1 text-xs text-red-600">{{ fileError }}</p>
                <p class="mt-1 text-[11px] text-slate-500">.csv, .xlsx, .xls · up to 4 MB</p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
                <button
                    type="button"
                    class="btn-primary"
                    :disabled="!file || uploading"
                    @click="uploadForPreview"
                >
                    {{ uploading ? 'Parsing…' : 'Upload + preview' }}
                </button>
            </div>
        </section>

        <!-- Step 2: preview + commit -->
        <section v-else-if="preview && !importResult" class="space-y-4">
            <div v-if="preview.summary.fatal_error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ preview.summary.fatal_error }}
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div class="rounded-md border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Total rows</div>
                    <div class="text-xl font-semibold tabular-nums">{{ preview.summary.total_rows }}</div>
                </div>
                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-emerald-700">Matched listings</div>
                    <div class="text-xl font-semibold tabular-nums text-emerald-700">{{ preview.summary.valid_rows }}</div>
                </div>
                <div class="rounded-md border p-3" :class="preview.summary.invalid_rows > 0 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white'">
                    <div class="text-[10px] font-mono uppercase tracking-wider" :class="preview.summary.invalid_rows > 0 ? 'text-red-700' : 'text-slate-500'">Errors / unmatched</div>
                    <div class="text-xl font-semibold tabular-nums" :class="preview.summary.invalid_rows > 0 ? 'text-red-700' : 'text-slate-700'">{{ preview.summary.invalid_rows }}</div>
                </div>
            </div>

            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                <label class="flex items-center gap-2 text-slate-700">
                    <input v-model="notifyOwners" type="checkbox" />
                    Notify owners about each new booking
                    <span class="text-slate-500">
                        (off by default — historical back-fills usually don't need it)
                    </span>
                </label>
            </div>

            <div>
                <button
                    type="button"
                    class="text-xs text-floor-accent hover:underline"
                    @click="showRowDetail = !showRowDetail"
                >
                    {{ showRowDetail ? 'Hide' : 'Show' }} per-row detail ({{ preview.rows.length }} rows)
                </button>
                <div v-if="showRowDetail" class="mt-2 max-h-72 overflow-y-auto rounded-md border border-slate-200 bg-white text-xs">
                    <table class="min-w-full">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Row</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Renter</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Listing match</th>
                                <th class="px-2 py-1 text-right text-[10px] uppercase text-slate-500">Total</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.rows" :key="row.row_num" class="border-t border-slate-100">
                                <td class="px-2 py-1 font-mono tabular-nums text-slate-500">{{ row.row_num }}</td>
                                <td class="px-2 py-1">
                                    <div>{{ row.data.renter_name ?? '—' }}</div>
                                    <div class="text-[10px] text-slate-500">{{ row.data.renter_email ?? row.data.renter_phone ?? '' }}</div>
                                </td>
                                <td class="px-2 py-1">
                                    <div v-if="row.matched_listing_id" class="font-mono text-[10px] text-emerald-700">
                                        ✓ {{ row.matched_listing_id.slice(0, 8) }}…
                                    </div>
                                    <div v-else class="text-[10px] text-slate-500">
                                        {{ row.data.resort_name ?? '—' }}
                                        <span v-if="row.data.listing_check_in_date">@ {{ row.data.listing_check_in_date }}</span>
                                    </div>
                                </td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums">
                                    ${{ row.data.total_price ?? '—' }}
                                </td>
                                <td class="px-2 py-1">
                                    <span v-if="row.valid" class="text-emerald-700">✓ ok</span>
                                    <span v-else class="text-red-700" :title="row.errors.join('; ')">
                                        ✕ {{ row.errors[0] }}{{ row.errors.length > 1 ? ` (+${row.errors.length - 1})` : '' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="fileError" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ fileError }}
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="startOver">← Pick another file</button>
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
                <button
                    type="button"
                    class="btn-primary"
                    :disabled="importDisabled || importing"
                    @click="confirmImport"
                >
                    {{ importing ? 'Importing…' : `Import ${preview.summary.valid_rows} booking${preview.summary.valid_rows === 1 ? '' : 's'}` }}
                </button>
            </div>
        </section>

        <!-- Step 3: result -->
        <section v-else-if="importResult" class="space-y-3">
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ importResult.message }}
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs">
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Bookings</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.bookings_created }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Owners notified</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.owners_notified }}</div>
                </div>
                <div class="rounded-md border p-2" :class="importResult.data.rows_skipped > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                    <div class="text-[10px] font-mono uppercase tracking-wider" :class="importResult.data.rows_skipped > 0 ? 'text-amber-700' : 'text-slate-500'">Skipped</div>
                    <div class="font-mono text-lg tabular-nums" :class="importResult.data.rows_skipped > 0 ? 'text-amber-700' : 'text-slate-700'">{{ importResult.data.rows_skipped }}</div>
                </div>
            </div>

            <details v-if="importResult.data.skip_reasons.length > 0" class="text-xs">
                <summary class="cursor-pointer text-slate-600">Why some rows were skipped ({{ importResult.data.skip_reasons.length }})</summary>
                <ul class="mt-1 list-disc pl-5 text-slate-600">
                    <li v-for="(s, i) in importResult.data.skip_reasons" :key="i">
                        Row {{ s.row_num }}: {{ s.reason }}
                    </li>
                </ul>
            </details>

            <div class="flex justify-end gap-2 border-t border-slate-200 pt-2">
                <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="startOver">Import another file</button>
                <button type="button" class="btn-primary" @click="emit('cancel')">Done</button>
            </div>
        </section>
    </div>
</template>
