<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import axios from 'axios';

/**
 * AI Live Coach panel — drops into the dialer screen, owner profile,
 * or anywhere an agent is composing text that the owner will hear or
 * read. Pipes utterances through /api/coach/suggestion and renders
 * the resulting hint + compliance flags.
 *
 * Usage:
 *   <LiveCoachPanel :call-id="callId" :auto-coach="true" />
 *
 * When auto-coach is on, each utterance (settled via 600ms debounce)
 * triggers a coach call. When off, the agent presses "Coach me" to
 * fetch a hint on demand. Red-zone responses surface a banner; the
 * parent component can listen to @red-zone if it wants to escalate.
 */

interface PhraseMatch {
    match: string;
    severity: string;
    reason: string;
    suggestion: string;
    offset: number;
    length: number;
}
interface CoachResponse {
    priority: 'compliance_rescue' | 'compliance_caution' | 'objection' | 'value' | 'close' | 'default';
    red_zone: boolean;
    hint: string;
    rationale: string;
    matches: PhraseMatch[];
    summary: { block_count: number; warn_count: number };
}

const props = withDefaults(defineProps<{
    callId?: string | null;
    dealId?: string | null;
    autoCoach?: boolean;
}>(), {
    callId: null,
    dealId: null,
    autoCoach: false,
});

const emit = defineEmits<{
    (e: 'red-zone', payload: { utterance: string; matches: PhraseMatch[] }): void;
    (e: 'hint', payload: CoachResponse): void;
}>();

const utterance = ref('');
const hint = ref<CoachResponse | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

let debounceTimer: number | undefined;

async function fetchHint(): Promise<void> {
    const text = utterance.value.trim();
    if (text === '') {
        hint.value = null;
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.post<CoachResponse>('/api/coach/suggestion', {
            call_id: props.callId,
            deal_id: props.dealId,
            utterance: text,
        });
        hint.value = data;
        emit('hint', data);
        if (data.red_zone) {
            emit('red-zone', { utterance: text, matches: data.matches });
        }
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        error.value = msg ?? 'Coach is unreachable.';
        hint.value = null;
    } finally {
        loading.value = false;
    }
}

watch(utterance, () => {
    if (! props.autoCoach) return;
    if (debounceTimer !== undefined) window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void fetchHint(), 600);
});

/* ------------------------------------------------------------------
 | Highlighting — turn the utterance into spans, with detected
 | phrases wrapped in colored marks. Renders the agent's text right
 | above the hint so the source of the warning is unambiguous.
 |------------------------------------------------------------------ */

interface Segment { text: string; severity: 'block' | 'warn' | null }

const segments = computed<Segment[]>(() => {
    const text = utterance.value;
    const matches = hint.value?.matches ?? [];
    if (text === '' || matches.length === 0) {
        return [{ text, severity: null }];
    }
    // Sort by offset; merge overlaps by preferring 'block' over 'warn'.
    const sorted = [...matches].sort((a, b) => a.offset - b.offset);
    const out: Segment[] = [];
    let cursor = 0;
    for (const m of sorted) {
        if (m.offset > cursor) {
            out.push({ text: text.slice(cursor, m.offset), severity: null });
        }
        const start = Math.max(m.offset, cursor);
        const end = m.offset + m.length;
        if (end > start) {
            out.push({
                text: text.slice(start, end),
                severity: m.severity as 'block' | 'warn',
            });
            cursor = end;
        }
    }
    if (cursor < text.length) {
        out.push({ text: text.slice(cursor), severity: null });
    }
    return out;
});

const priorityColor = computed(() => {
    if (!hint.value) return 'text-deck-soft';
    return {
        compliance_rescue: 'text-floor-lose',
        compliance_caution: 'text-floor-accent',
        objection: 'text-floor-info',
        value: 'text-floor-win',
        close: 'text-floor-win',
        default: 'text-deck-soft',
    }[hint.value.priority];
});

const priorityLabel = computed(() => {
    if (!hint.value) return '';
    return {
        compliance_rescue: 'RED ZONE · COMPLIANCE RESCUE',
        compliance_caution: 'CAUTION',
        objection: 'OBJECTION',
        value: 'VALUE',
        close: 'CLOSE',
        default: 'COACH',
    }[hint.value.priority];
});
</script>

<template>
    <div class="deck-card overflow-hidden">
        <header class="border-b border-deck-line px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="deck-dot-live"></span>
                <div class="text-sm font-semibold text-deck-text">AI Live Coach</div>
                <span v-if="hint?.red_zone" class="pill bg-floor-lose/15 text-floor-lose ring-1 ring-floor-lose/30 font-mono">
                    RED ZONE
                </span>
            </div>
            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                {{ autoCoach ? 'auto · 600ms debounce' : 'on-demand' }}
            </div>
        </header>

        <div class="p-4 space-y-3">
            <!-- Utterance input -->
            <div>
                <label class="label">What just got said</label>
                <textarea
                    v-model="utterance"
                    rows="3"
                    placeholder="Paste or type the agent's last 1-2 sentences…"
                    class="input mt-1 text-sm"
                ></textarea>
                <div class="mt-2 flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn-ghost text-xs"
                        :disabled="loading || utterance.trim() === ''"
                        @click="fetchHint"
                    >
                        {{ loading ? 'Coaching…' : 'Coach me' }}
                    </button>
                </div>
            </div>

            <!-- Highlighted source with detected phrases marked -->
            <div v-if="hint && hint.matches.length > 0" class="rounded-md border border-deck-line bg-deck-bg p-3 text-xs">
                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-1">
                    Detected ({{ hint.summary.block_count }} block · {{ hint.summary.warn_count }} warn)
                </div>
                <div class="font-mono leading-relaxed whitespace-pre-wrap text-deck-soft">
                    <span
                        v-for="(seg, i) in segments"
                        :key="i"
                        :class="seg.severity === 'block'
                            ? 'bg-floor-lose/30 text-floor-lose px-0.5 rounded'
                            : seg.severity === 'warn'
                                ? 'bg-floor-accent/30 text-floor-accent px-0.5 rounded'
                                : ''"
                    >{{ seg.text }}</span>
                </div>
            </div>

            <!-- The hint -->
            <div v-if="hint" class="rounded-md border-l-2 px-3 py-2"
                 :class="hint.red_zone
                     ? 'border-floor-lose bg-floor-lose/5'
                     : hint.priority === 'compliance_caution' ? 'border-floor-accent bg-floor-accent/5'
                     : hint.priority === 'objection' ? 'border-floor-info bg-floor-info/5'
                     : 'border-deck-line bg-deck-bg'">
                <div class="text-[10px] font-mono uppercase tracking-[0.18em] mb-1" :class="priorityColor">
                    {{ priorityLabel }}
                </div>
                <p class="text-sm text-deck-text leading-snug">{{ hint.hint }}</p>
                <p v-if="hint.rationale" class="mt-2 text-[10px] font-mono uppercase tracking-wider text-deck-dim italic">
                    {{ hint.rationale }}
                </p>
            </div>

            <!-- Error -->
            <div v-if="error" class="rounded-md border border-floor-lose/30 bg-floor-lose/10 px-3 py-2 text-xs text-floor-lose">
                {{ error }}
            </div>

            <!-- Empty state -->
            <div v-if="!hint && !loading && !error" class="text-center text-xs text-deck-dim italic">
                Type what the agent just said to get a coaching hint. Compliance rescues take priority over every other suggestion.
            </div>
        </div>
    </div>
</template>
