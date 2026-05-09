<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const emit = defineEmits<{
    (e: 'disposition', value: { disposition: string; notes: string | null }): void;
}>();

const notes = ref('');
const selected = ref<string | null>(null);

interface Option {
    key: string;
    label: string;
    shortcut: string;
    color: string;
}

// Keyboard shortcuts: 1–8 across the keyboard. The ScriptPanel suggests
// what to say; this panel is what the agent ACTUALLY clicks fastest.
const options: Option[] = [
    { key: 'interested', label: 'Interested', shortcut: '1', color: 'bg-emerald-600 hover:bg-emerald-500' },
    { key: 'pitch_presented', label: 'Pitched', shortcut: '2', color: 'bg-emerald-700 hover:bg-emerald-600' },
    { key: 'callback', label: 'Callback', shortcut: '3', color: 'bg-amber-600 hover:bg-amber-500' },
    { key: 'voicemail', label: 'Voicemail', shortcut: '4', color: 'bg-amber-700 hover:bg-amber-600' },
    { key: 'no_answer', label: 'No answer', shortcut: '5', color: 'bg-slate-600 hover:bg-slate-500' },
    { key: 'busy', label: 'Busy', shortcut: '6', color: 'bg-slate-600 hover:bg-slate-500' },
    { key: 'not_interested', label: 'Not interested', shortcut: '7', color: 'bg-rose-600 hover:bg-rose-500' },
    { key: 'dnc_request', label: 'DNC request', shortcut: '8', color: 'bg-rose-800 hover:bg-rose-700' },
    { key: 'sale_closed', label: 'SALE', shortcut: 'S', color: 'bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold' },
    { key: 'transferred_to_closer', label: 'To Closer', shortcut: 'T', color: 'bg-blue-600 hover:bg-blue-500' },
];

function pick(disposition: string): void {
    selected.value = disposition;
    emit('disposition', { disposition, notes: notes.value || null });
    notes.value = '';
}

function onKey(e: KeyboardEvent): void {
    // Don't intercept while typing in the notes textarea
    const target = e.target as HTMLElement;
    if (target.tagName === 'TEXTAREA' || target.tagName === 'INPUT') return;

    const opt = options.find((o) => o.shortcut.toLowerCase() === e.key.toLowerCase());
    if (opt) {
        e.preventDefault();
        pick(opt.key);
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <section class="dialer-panel flex flex-col gap-3 p-5">
        <header class="flex items-center justify-between">
            <h2 class="text-xs uppercase tracking-wider text-slate-400">Disposition</h2>
            <span class="text-xs text-slate-500">Press shortcut key to apply</span>
        </header>

        <textarea
            v-model="notes"
            rows="2"
            placeholder="Notes (optional)"
            class="w-full rounded-md border-slate-700 bg-slate-900/40 text-sm text-slate-100 placeholder-slate-500 focus:border-emerald-500 focus:ring-emerald-500"
        ></textarea>

        <div class="grid grid-cols-2 gap-2">
            <button
                v-for="opt in options"
                :key="opt.key"
                class="relative rounded-md px-3 py-2 text-sm text-white transition-colors"
                :class="[opt.color, selected === opt.key ? 'ring-2 ring-white/40' : '']"
                @click="pick(opt.key)"
            >
                <span class="absolute left-2 top-1 text-[10px] font-mono text-white/70">{{ opt.shortcut }}</span>
                {{ opt.label }}
            </button>
        </div>
    </section>
</template>
