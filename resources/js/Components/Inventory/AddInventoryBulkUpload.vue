<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';

/**
 * Bulk inventory upload — file pick → preview → confirm.
 *
 * Two-step flow because we don't want to commit "47 new resorts"
 * just because the operator clicked Import. The preview surfaces
 * row-level errors AND per-new-entity approval so a typo in the
 * spreadsheet ("Marriot" vs "Marriott") gets caught before a
 * duplicate resort row lands in the tenant.
 */

interface NewResort {
    key: string;
    name: string;
    brand: string | null;
    city: string;
    state: string;
    country: string;
    row_count: number;
}

interface NewUnit {
    key: string;
    resort_key: string;
    resort_name: string;
    unit_type: string;
    sleeps: number;
    features: string[];
    row_count: number;
}

interface PreviewRow {
    row_num: number;
    valid: boolean;
    errors: string[];
    warnings: string[];
    data: Record<string, unknown> & {
        resort_name?: string;
        city?: string;
        state?: string;
        unit_type?: string;
        sleeps?: number;
        check_in_date?: string | null;
        check_out_date?: string | null;
        base_price?: number | null;
    };
    resort_match: 'new' | 'existing';
    unit_match: 'new' | 'existing';
}

interface PreviewResponse {
    preview_token: string;
    summary: {
        total_rows: number;
        valid_rows: number;
        invalid_rows: number;
        new_resorts_count?: number;
        new_units_count?: number;
        fatal_error?: string;
    };
    rows: PreviewRow[];
    new_resorts: NewResort[];
    new_units: NewUnit[];
}

interface ImportResponse {
    message: string;
    data: {
        resorts_created: number;
        units_created: number;
        availability_created: number;
        rows_skipped: number;
        skip_reasons: Array<{ row_num: number; reason: string }>;
    };
}

const emit = defineEmits<{ (e: 'imported'): void; (e: 'cancel'): void }>();

const file = ref<File | null>(null);
const fileError = ref<string | null>(null);
const uploading = ref(false);
const importing = ref(false);

const preview = ref<PreviewResponse | null>(null);
const importResult = ref<ImportResponse | null>(null);

const approvedResortKeys = ref<Set<string>>(new Set());
const approvedUnitKeys = ref<Set<string>>(new Set());
const showRowDetail = ref(false);

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
            '/api/inventory/bulk-preview',
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );
        preview.value = data;
        // Default: every new entity is approved. Operator can untick.
        approvedResortKeys.value = new Set(data.new_resorts.map((r) => r.key));
        approvedUnitKeys.value = new Set(data.new_units.map((u) => u.key));
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const msg = e.response?.data?.errors?.file?.[0]
            ?? e.response?.data?.message
            ?? 'Upload failed.';
        fileError.value = msg;
    } finally {
        uploading.value = false;
    }
}

function toggleResort(key: string, on: boolean): void {
    const s = new Set(approvedResortKeys.value);
    if (on) s.add(key); else s.delete(key);
    approvedResortKeys.value = s;
    // Untick all units belonging to an unticked resort — they can't
    // be created without their parent resort.
    if (!on && preview.value) {
        const remaining = new Set(approvedUnitKeys.value);
        for (const u of preview.value.new_units) {
            if (u.resort_key === key) remaining.delete(u.key);
        }
        approvedUnitKeys.value = remaining;
    }
}

function toggleUnit(key: string, on: boolean): void {
    const s = new Set(approvedUnitKeys.value);
    if (on) s.add(key); else s.delete(key);
    approvedUnitKeys.value = s;
}

const approvedUnitsForResort = (resortKey: string): number => {
    if (!preview.value) return 0;
    return preview.value.new_units
        .filter((u) => u.resort_key === resortKey && approvedUnitKeys.value.has(u.key))
        .length;
};

const importDisabled = computed(() => {
    if (!preview.value) return true;
    return preview.value.summary.valid_rows === 0;
});

async function confirmImport(): Promise<void> {
    if (!preview.value) return;
    importing.value = true;
    try {
        const { data } = await axios.post<ImportResponse>('/api/inventory/bulk-import', {
            preview_token: preview.value.preview_token,
            approved_resort_keys: Array.from(approvedResortKeys.value),
            approved_unit_keys: Array.from(approvedUnitKeys.value),
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
    approvedResortKeys.value = new Set();
    approvedUnitKeys.value = new Set();
}
</script>

<template>
    <div class="space-y-4">
        <!-- Step 1: file pick -->
        <section v-if="!preview && !importResult" class="space-y-3">
            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <div class="font-medium text-slate-800">CSV / Excel format</div>
                <div class="mt-1">
                    Required columns: resort_name, city, state, unit_type, sleeps,
                    check_in_date, check_out_date, base_price.
                    Optional: resort_brand, country, currency, features.
                </div>
                <div class="mt-1">
                    Header names are flexible — "check-in", "checkin", "arrival"
                    all map to check_in_date.
                </div>
                <a
                    href="/api/inventory/template.csv"
                    class="mt-2 inline-block text-floor-accent hover:underline"
                    download
                >
                    ↓ Download CSV template
                </a>
            </div>

            <div>
                <label class="label">Inventory file <span class="text-red-600">*</span></label>
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

        <!-- Step 2: preview + approve -->
        <section v-else-if="preview && !importResult" class="space-y-4">
            <!-- Fatal parse error: no usable rows -->
            <div v-if="preview.summary.fatal_error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ preview.summary.fatal_error }}
            </div>

            <!-- Summary strip -->
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-md border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Total rows</div>
                    <div class="text-xl font-semibold tabular-nums">{{ preview.summary.total_rows }}</div>
                </div>
                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-emerald-700">Valid</div>
                    <div class="text-xl font-semibold tabular-nums text-emerald-700">{{ preview.summary.valid_rows }}</div>
                </div>
                <div class="rounded-md border p-3" :class="preview.summary.invalid_rows > 0 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white'">
                    <div class="text-[10px] font-mono uppercase tracking-wider" :class="preview.summary.invalid_rows > 0 ? 'text-red-700' : 'text-slate-500'">Errors</div>
                    <div class="text-xl font-semibold tabular-nums" :class="preview.summary.invalid_rows > 0 ? 'text-red-700' : 'text-slate-700'">{{ preview.summary.invalid_rows }}</div>
                </div>
                <div class="rounded-md border border-amber-200 bg-amber-50 p-3">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-amber-700">New entities</div>
                    <div class="text-xl font-semibold tabular-nums text-amber-700">
                        {{ preview.new_resorts.length }} resorts · {{ preview.new_units.length }} units
                    </div>
                </div>
            </div>

            <!-- New resorts approval -->
            <div v-if="preview.new_resorts.length > 0" class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-mono uppercase tracking-wider text-slate-600">
                    New resorts to create ({{ approvedResortKeys.size }} / {{ preview.new_resorts.length }} approved)
                </div>
                <div class="max-h-48 overflow-y-auto">
                    <label
                        v-for="r in preview.new_resorts"
                        :key="r.key"
                        class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            :checked="approvedResortKeys.has(r.key)"
                            class="mt-1"
                            @change="toggleResort(r.key, ($event.target as HTMLInputElement).checked)"
                        />
                        <div class="flex-1">
                            <div class="font-medium text-slate-900">
                                {{ r.name }}
                                <span v-if="r.brand" class="text-xs font-normal text-slate-500">· {{ r.brand }}</span>
                            </div>
                            <div class="text-xs text-slate-600">{{ r.city }}, {{ r.state }}, {{ r.country }}</div>
                            <div class="text-[10px] text-slate-500">
                                {{ r.row_count }} row{{ r.row_count === 1 ? '' : 's' }} in upload ·
                                {{ approvedUnitsForResort(r.key) }} unit type(s) approved
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- New units approval -->
            <div v-if="preview.new_units.length > 0" class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-mono uppercase tracking-wider text-slate-600">
                    New unit types to create ({{ approvedUnitKeys.size }} / {{ preview.new_units.length }} approved)
                </div>
                <div class="max-h-48 overflow-y-auto">
                    <label
                        v-for="u in preview.new_units"
                        :key="u.key"
                        class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            :checked="approvedUnitKeys.has(u.key)"
                            :disabled="!approvedResortKeys.has(u.resort_key)"
                            class="mt-1"
                            @change="toggleUnit(u.key, ($event.target as HTMLInputElement).checked)"
                        />
                        <div class="flex-1">
                            <div class="text-sm text-slate-900">
                                <span class="font-medium">{{ u.unit_type }}</span>
                                <span class="text-xs text-slate-500"> · sleeps {{ u.sleeps }}</span>
                                <span v-if="u.features.length" class="text-xs text-slate-500">
                                    · {{ u.features.join(', ') }}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-600">at {{ u.resort_name }}</div>
                            <div class="text-[10px] text-slate-500">
                                {{ u.row_count }} row{{ u.row_count === 1 ? '' : 's' }} in upload
                                <span v-if="!approvedResortKeys.has(u.resort_key)" class="text-amber-700">
                                    · parent resort not approved
                                </span>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Row detail toggle -->
            <div>
                <button
                    type="button"
                    class="text-xs text-floor-accent hover:underline"
                    @click="showRowDetail = !showRowDetail"
                >
                    {{ showRowDetail ? 'Hide' : 'Show' }} per-row detail ({{ preview.rows.length }} rows)
                </button>
                <div v-if="showRowDetail" class="mt-2 max-h-64 overflow-y-auto rounded-md border border-slate-200 bg-white text-xs">
                    <table class="min-w-full">
                        <thead class="sticky top-0 bg-slate-50">
                            <tr>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Row</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Resort / Unit</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Dates</th>
                                <th class="px-2 py-1 text-right text-[10px] uppercase text-slate-500">Price</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.rows" :key="row.row_num" class="border-t border-slate-100">
                                <td class="px-2 py-1 font-mono tabular-nums text-slate-500">{{ row.row_num }}</td>
                                <td class="px-2 py-1">
                                    <div>{{ row.data.resort_name }} <span class="text-slate-500">· {{ row.data.city }}, {{ row.data.state }}</span></div>
                                    <div class="text-[10px] text-slate-500">{{ row.data.unit_type }} sleeps {{ row.data.sleeps }}</div>
                                </td>
                                <td class="px-2 py-1 font-mono tabular-nums">
                                    {{ row.data.check_in_date ?? '—' }} → {{ row.data.check_out_date ?? '—' }}
                                </td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums">
                                    ${{ row.data.base_price !== null && row.data.base_price !== undefined ? row.data.base_price : '—' }}
                                </td>
                                <td class="px-2 py-1">
                                    <span v-if="row.valid" class="text-emerald-700">✓ ok</span>
                                    <span v-else class="text-red-700" :title="row.errors.join('; ')">
                                        ✕ {{ row.errors[0] }}{{ row.errors.length > 1 ? ` (+${row.errors.length - 1} more)` : '' }}
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
                    {{ importing ? 'Importing…' : `Import ${preview.summary.valid_rows} row${preview.summary.valid_rows === 1 ? '' : 's'}` }}
                </button>
            </div>
        </section>

        <!-- Step 3: result -->
        <section v-else-if="importResult" class="space-y-3">
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ importResult.message }}
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Availability rows</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.availability_created }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">New resorts</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.resorts_created }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">New units</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.units_created }}</div>
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
