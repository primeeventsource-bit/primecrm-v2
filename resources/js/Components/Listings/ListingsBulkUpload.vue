<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';

/**
 * Bulk-upload tab for listings — file pick → preview → confirm.
 *
 * Per the agreed workflow: parse server-side, group new owners and
 * new properties, let the operator approve each new entity before
 * commit. Unticking a parent owner cascades to its properties so
 * orphan-property creation is impossible.
 */

interface NewOwner {
    key: string;
    email: string | null;
    phone: string | null;
    first_name: string | null;
    last_name: string | null;
    row_count: number;
}

interface NewProperty {
    key: string;
    owner_key: string;
    owner_email: string | null;
    resort_name: string;
    resort_brand: string | null;
    city: string;
    state: string;
    country: string;
    row_count: number;
}

interface PreviewRow {
    row_num: number;
    valid: boolean;
    errors: string[];
    warnings: string[];
    data: Record<string, unknown> & {
        owner_email?: string | null;
        owner_phone?: string | null;
        resort_name?: string;
        city?: string;
        state?: string;
        check_in_date?: string | null;
        check_out_date?: string | null;
        asking_price?: number | null;
    };
    owner_match: 'new' | 'existing';
    property_match: 'new' | 'existing';
}

interface PreviewResponse {
    preview_token: string;
    summary: {
        total_rows: number;
        valid_rows: number;
        invalid_rows: number;
        new_owners_count?: number;
        new_properties_count?: number;
        fatal_error?: string;
    };
    rows: PreviewRow[];
    new_owners: NewOwner[];
    new_properties: NewProperty[];
}

interface ImportResponse {
    message: string;
    data: {
        listings_created: number;
        owners_created: number;
        properties_created: number;
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

const approvedOwnerKeys = ref<Set<string>>(new Set());
const approvedPropertyKeys = ref<Set<string>>(new Set());
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
            '/api/listings/bulk-preview',
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } },
        );
        preview.value = data;
        approvedOwnerKeys.value = new Set(data.new_owners.map((o) => o.key));
        approvedPropertyKeys.value = new Set(data.new_properties.map((p) => p.key));
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        fileError.value = e.response?.data?.errors?.file?.[0]
            ?? e.response?.data?.message
            ?? 'Upload failed.';
    } finally {
        uploading.value = false;
    }
}

function toggleOwner(key: string, on: boolean): void {
    const s = new Set(approvedOwnerKeys.value);
    if (on) s.add(key); else s.delete(key);
    approvedOwnerKeys.value = s;
    // Cascade: unticking an owner unticks all properties beneath it,
    // since the property can't be created without its owner.
    if (!on && preview.value) {
        const remaining = new Set(approvedPropertyKeys.value);
        for (const p of preview.value.new_properties) {
            if (p.owner_key === key) remaining.delete(p.key);
        }
        approvedPropertyKeys.value = remaining;
    }
}

function toggleProperty(key: string, on: boolean): void {
    const s = new Set(approvedPropertyKeys.value);
    if (on) s.add(key); else s.delete(key);
    approvedPropertyKeys.value = s;
}

const importDisabled = computed(() => {
    if (!preview.value) return true;
    return preview.value.summary.valid_rows === 0;
});

async function confirmImport(): Promise<void> {
    if (!preview.value) return;
    importing.value = true;
    try {
        const { data } = await axios.post<ImportResponse>('/api/listings/bulk-import', {
            preview_token: preview.value.preview_token,
            approved_owner_keys: Array.from(approvedOwnerKeys.value),
            approved_property_keys: Array.from(approvedPropertyKeys.value),
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
    approvedOwnerKeys.value = new Set();
    approvedPropertyKeys.value = new Set();
}

function ownerLabel(o: NewOwner): string {
    const name = [o.first_name, o.last_name].filter(Boolean).join(' ');
    if (name) return name;
    return o.email ?? o.phone ?? '(no name)';
}
</script>

<template>
    <div class="space-y-4">
        <!-- Step 1: file pick -->
        <section v-if="!preview && !importResult" class="space-y-3">
            <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <div class="font-medium text-slate-800">CSV / Excel format</div>
                <div class="mt-1">
                    Required: at least one of owner_email/owner_phone, resort_name, city,
                    state, check_in_date, check_out_date, asking_price.
                    Optional: owner names, resort_brand, country, unit_number, bedrooms,
                    sleeps, ownership_type, reserve_price, our_commission_pct,
                    marketing_description, go_live.
                </div>
                <div class="mt-1">
                    Header names are flexible — "asking rate", "list price", "checkin"
                    all map to canonical columns.
                </div>
                <a
                    href="/api/listings/template.csv"
                    class="mt-2 inline-block text-floor-accent hover:underline"
                    download
                >
                    ↓ Download CSV template
                </a>
            </div>

            <div>
                <label class="label">Listings file <span class="text-red-600">*</span></label>
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
            <div v-if="preview.summary.fatal_error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ preview.summary.fatal_error }}
            </div>

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
                        {{ preview.new_owners.length }} owners · {{ preview.new_properties.length }} properties
                    </div>
                </div>
            </div>

            <div v-if="preview.new_owners.length > 0" class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-mono uppercase tracking-wider text-slate-600">
                    New owners to create ({{ approvedOwnerKeys.size }} / {{ preview.new_owners.length }} approved)
                </div>
                <div class="max-h-48 overflow-y-auto">
                    <label
                        v-for="o in preview.new_owners"
                        :key="o.key"
                        class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            :checked="approvedOwnerKeys.has(o.key)"
                            class="mt-1"
                            @change="toggleOwner(o.key, ($event.target as HTMLInputElement).checked)"
                        />
                        <div class="flex-1">
                            <div class="font-medium text-slate-900">{{ ownerLabel(o) }}</div>
                            <div class="text-xs text-slate-600">
                                <span v-if="o.email">{{ o.email }}</span>
                                <span v-if="o.email && o.phone"> · </span>
                                <span v-if="o.phone">{{ o.phone }}</span>
                            </div>
                            <div class="text-[10px] text-slate-500">
                                {{ o.row_count }} row{{ o.row_count === 1 ? '' : 's' }} in upload
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div v-if="preview.new_properties.length > 0" class="rounded-md border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-mono uppercase tracking-wider text-slate-600">
                    New properties to create ({{ approvedPropertyKeys.size }} / {{ preview.new_properties.length }} approved)
                </div>
                <div class="max-h-48 overflow-y-auto">
                    <label
                        v-for="p in preview.new_properties"
                        :key="p.key"
                        class="flex cursor-pointer items-start gap-2 border-b border-slate-100 px-3 py-2 text-sm last:border-b-0 hover:bg-slate-50"
                    >
                        <input
                            type="checkbox"
                            :checked="approvedPropertyKeys.has(p.key)"
                            :disabled="!approvedOwnerKeys.has(p.owner_key) && preview.new_owners.some(o => o.key === p.owner_key)"
                            class="mt-1"
                            @change="toggleProperty(p.key, ($event.target as HTMLInputElement).checked)"
                        />
                        <div class="flex-1">
                            <div class="font-medium text-slate-900">
                                {{ p.resort_name }}
                                <span v-if="p.resort_brand" class="text-xs font-normal text-slate-500">· {{ p.resort_brand }}</span>
                            </div>
                            <div class="text-xs text-slate-600">{{ p.city }}, {{ p.state }}, {{ p.country }}</div>
                            <div class="text-[11px] text-slate-600">for {{ p.owner_email ?? p.owner_key.replace(/^[ep]:/, '') }}</div>
                            <div class="text-[10px] text-slate-500">
                                {{ p.row_count }} row{{ p.row_count === 1 ? '' : 's' }} in upload
                                <span v-if="!approvedOwnerKeys.has(p.owner_key) && preview.new_owners.some(o => o.key === p.owner_key)" class="text-amber-700">
                                    · parent owner not approved
                                </span>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

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
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Owner / Resort</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Dates</th>
                                <th class="px-2 py-1 text-right text-[10px] uppercase text-slate-500">Asking</th>
                                <th class="px-2 py-1 text-left text-[10px] uppercase text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in preview.rows" :key="row.row_num" class="border-t border-slate-100">
                                <td class="px-2 py-1 font-mono tabular-nums text-slate-500">{{ row.row_num }}</td>
                                <td class="px-2 py-1">
                                    <div>{{ row.data.owner_email ?? row.data.owner_phone ?? '—' }}</div>
                                    <div class="text-[10px] text-slate-500">{{ row.data.resort_name }} · {{ row.data.city }}, {{ row.data.state }}</div>
                                </td>
                                <td class="px-2 py-1 font-mono tabular-nums">
                                    {{ row.data.check_in_date ?? '—' }} → {{ row.data.check_out_date ?? '—' }}
                                </td>
                                <td class="px-2 py-1 text-right font-mono tabular-nums">
                                    ${{ row.data.asking_price ?? '—' }}
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
                    {{ importing ? 'Importing…' : `Import ${preview.summary.valid_rows} listing${preview.summary.valid_rows === 1 ? '' : 's'}` }}
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
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">Listings</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.listings_created }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">New owners</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.owners_created }}</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-white p-2">
                    <div class="text-[10px] font-mono uppercase tracking-wider text-slate-500">New properties</div>
                    <div class="font-mono text-lg tabular-nums">{{ importResult.data.properties_created }}</div>
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
