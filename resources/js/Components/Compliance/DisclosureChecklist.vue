<script setup lang="ts">
import { computed } from 'vue';

/**
 * The five mandatory disclosures every listing-fee call must capture.
 *
 * On the dialer screen, the closer sees this checklist in real time
 * while talking to the owner. Boxes auto-tick as the transcription
 * scanner detects each phrase (wired in D8). The closer can also
 * tick manually. The compliance shield at the top reflects status:
 *
 *   green  All 5 captured — proceed to close.
 *   amber  3-4 captured — surface the missing items.
 *   red    0-2 captured — closer cannot close the agreement yet.
 *
 * Per §5.1 of the prompt — these phrases must be spoken on every
 * call that takes a listing fee. Missing any one blocks listing
 * distribution downstream (enforced server-side via the verification
 * gate on /api/listings/{id}/distributions).
 */

interface DisclosureCaptures {
    tcpa_consent_captured: boolean;
    recording_disclosure_made: boolean;
    no_guarantee_disclosure_made: boolean;
    refund_policy_disclosure_made: boolean;
    total_fee_stated_clearly: boolean;
}

const props = defineProps<{
    captures: DisclosureCaptures;
    readonly?: boolean;          // true on review queue (toggle still works for reviewers)
    showStateAddenda?: boolean;  // state-specific addenda (FL/CA) — future
}>();

const emit = defineEmits<{
    (e: 'toggle', field: keyof DisclosureCaptures, value: boolean): void;
}>();

interface Item {
    key: keyof DisclosureCaptures;
    title: string;
    script: string;
}

const items: Item[] = [
    {
        key: 'recording_disclosure_made',
        title: 'Recording disclosure',
        script: '"This call is being recorded for training and quality purposes."',
    },
    {
        key: 'tcpa_consent_captured',
        title: 'TCPA consent',
        script: 'Owner gave express verbal/written consent to be contacted.',
    },
    {
        key: 'no_guarantee_disclosure_made',
        title: 'No-rental-guarantee',
        script: '"We cannot guarantee that your timeshare will rent."',
    },
    {
        key: 'total_fee_stated_clearly',
        title: 'Total fee stated',
        script: '"The total listing fee is $X.XX." (stated verbatim, not implied)',
    },
    {
        key: 'refund_policy_disclosure_made',
        title: 'Refund policy',
        script: '"Non-refundable after [Y] days unless [stated conditions]."',
    },
];

const capturedCount = computed(() =>
    Object.values(props.captures).filter(Boolean).length
);
const totalCount = computed(() => items.length);
const isComplete = computed(() => capturedCount.value === totalCount.value);

const shieldColor = computed(() => {
    if (capturedCount.value === totalCount.value) return 'text-floor-win';
    if (capturedCount.value >= 3) return 'text-floor-accent';
    return 'text-floor-lose';
});

const shieldLabel = computed(() => {
    if (isComplete.value) return 'All disclosures captured';
    const missing = totalCount.value - capturedCount.value;
    return `${missing} disclosure${missing === 1 ? '' : 's'} missing`;
});

function onToggle(item: Item): void {
    if (props.readonly) return;
    emit('toggle', item.key, ! props.captures[item.key]);
}
</script>

<template>
    <div class="deck-card overflow-hidden">
        <!-- Compliance shield header -->
        <header class="flex items-center justify-between border-b border-deck-line px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="text-lg" :class="shieldColor">{{ isComplete ? '✓' : '⚠' }}</span>
                <div>
                    <div class="text-sm font-semibold" :class="shieldColor">
                        Compliance shield
                    </div>
                    <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                        {{ shieldLabel }}
                    </div>
                </div>
            </div>
            <div class="text-right">
                <div class="deck-num text-2xl" :class="shieldColor">
                    {{ capturedCount }}/{{ totalCount }}
                </div>
                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                    captured
                </div>
            </div>
        </header>

        <!-- Item list -->
        <ul class="divide-y divide-deck-line/50">
            <li
                v-for="item in items"
                :key="item.key"
                class="px-4 py-3 transition-colors"
                :class="captures[item.key] ? 'bg-floor-win/5' : 'hover:bg-deck-raised/40'"
            >
                <label
                    class="flex items-start gap-3"
                    :class="readonly ? '' : 'cursor-pointer'"
                >
                    <input
                        type="checkbox"
                        :checked="captures[item.key]"
                        :disabled="readonly === true && false /* always actionable for reviewers */"
                        class="mt-1 rounded border-deck-line bg-deck-surface text-floor-win focus:ring-floor-win shrink-0"
                        @change="onToggle(item)"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium"
                             :class="captures[item.key] ? 'text-floor-win' : 'text-deck-text'">
                            <span v-if="captures[item.key]" class="mr-1">✓</span>{{ item.title }}
                        </div>
                        <div class="text-xs italic text-deck-soft mt-0.5">
                            {{ item.script }}
                        </div>
                    </div>
                </label>
            </li>
        </ul>

        <!-- Gate notice -->
        <div
            v-if="!isComplete"
            class="border-t border-deck-line bg-floor-lose/5 px-4 py-2 text-xs text-floor-lose flex items-center gap-2"
        >
            <span class="font-mono uppercase tracking-wider">!</span>
            <span>
                Listing distribution is blocked until every box is ticked. State AG audits ask for this checklist by name.
            </span>
        </div>
    </div>
</template>
