<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';

interface Note {
    id: string;
    notable_type: 'lead' | 'customer' | string;
    notable_id: string;
    user_id: string | null;
    author_name: string | null;
    kind: string;
    body: string;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

const props = defineProps<{
    /** 'lead' or 'customer' — must match the API's notable_type alias. */
    notableType: 'lead' | 'customer';
    notableId: string;
    currentUserId?: string | null;
}>();

const notes = ref<Note[]>([]);
const loading = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
const draftBody = ref('');
const draftKind = ref<'note' | 'call' | 'email' | 'sms'>('note');

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get<Paginated<Note>>('/api/notes', {
            params: { notable_type: props.notableType, notable_id: props.notableId, per_page: 100 },
        });
        notes.value = data.data;
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not load notes.';
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    if (!draftBody.value.trim()) return;
    submitting.value = true;
    error.value = null;
    try {
        const { data } = await axios.post<{ data: Note }>('/api/notes', {
            notable_type: props.notableType,
            notable_id: props.notableId,
            kind: draftKind.value,
            body: draftBody.value.trim(),
        });
        notes.value = [data.data, ...notes.value];
        draftBody.value = '';
        draftKind.value = 'note';
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not save the note.';
    } finally {
        submitting.value = false;
    }
}

async function remove(id: string): Promise<void> {
    if (!confirm('Delete this note?')) return;
    try {
        await axios.delete(`/api/notes/${id}`);
        notes.value = notes.value.filter((n) => n.id !== id);
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Could not delete the note.';
    }
}

function kindBadge(kind: string): string {
    return {
        note: 'bg-slate-100 text-slate-700',
        call: 'bg-blue-100 text-blue-700',
        email: 'bg-emerald-100 text-emerald-700',
        sms: 'bg-purple-100 text-purple-700',
        system: 'bg-amber-100 text-amber-700',
    }[kind] ?? 'bg-slate-100 text-slate-700';
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString();
}

watch(() => [props.notableType, props.notableId], () => void load());
onMounted(load);
</script>

<template>
    <section class="panel">
        <header class="border-b border-slate-200 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Communication history</h3>
            <p class="mt-0.5 text-xs text-slate-500">Notes, call summaries, email threads — newest first.</p>
        </header>

        <form class="border-b border-slate-200 px-4 py-3 space-y-2" @submit.prevent="submit">
            <div class="flex gap-2">
                <select v-model="draftKind" class="input w-32 text-sm">
                    <option value="note">Note</option>
                    <option value="call">Call</option>
                    <option value="email">Email</option>
                    <option value="sms">SMS</option>
                </select>
                <textarea
                    v-model="draftBody"
                    rows="2"
                    placeholder="Add a note about this contact…"
                    class="input flex-1 text-sm resize-y"
                    maxlength="5000"
                />
            </div>
            <div class="flex justify-between items-center">
                <span v-if="error" class="text-xs text-red-600">{{ error }}</span>
                <span v-else class="text-xs text-slate-400">{{ draftBody.length }}/5000</span>
                <button type="submit" class="btn-primary text-sm" :disabled="submitting || !draftBody.trim()">
                    {{ submitting ? 'Saving…' : 'Add to timeline' }}
                </button>
            </div>
        </form>

        <ul v-if="notes.length" class="divide-y divide-slate-100">
            <li v-for="n in notes" :key="n.id" class="px-4 py-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span class="pill" :class="kindBadge(n.kind)">{{ n.kind }}</span>
                            <span class="font-medium text-slate-700">{{ n.author_name ?? 'system' }}</span>
                            <span>·</span>
                            <span>{{ formatTime(n.created_at) }}</span>
                        </div>
                        <p class="mt-1.5 whitespace-pre-wrap text-sm text-slate-800">{{ n.body }}</p>
                    </div>
                    <button
                        v-if="currentUserId && n.user_id === currentUserId"
                        type="button"
                        class="text-xs text-slate-400 hover:text-rose-600"
                        title="Delete"
                        @click="remove(n.id)"
                    >×</button>
                </div>
            </li>
        </ul>
        <div v-else-if="!loading" class="px-4 py-8 text-center text-sm text-slate-500">
            No history yet. Add the first note above.
        </div>
        <div v-else class="px-4 py-8 text-center text-sm text-slate-400">
            Loading…
        </div>
    </section>
</template>
