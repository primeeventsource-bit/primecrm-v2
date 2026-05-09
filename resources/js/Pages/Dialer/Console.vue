<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LeadInfoPanel from '@/Components/Dialer/LeadInfoPanel.vue';
import CallControlPanel from '@/Components/Dialer/CallControlPanel.vue';
import ScriptPanel from '@/Components/Dialer/ScriptPanel.vue';
import DispositionPanel from '@/Components/Dialer/DispositionPanel.vue';
import SessionStatusBar from '@/Components/Dialer/SessionStatusBar.vue';
import { useDialerSession } from '@/Composables/useDialerSession';
import { useActiveCall } from '@/Composables/useActiveCall';
import { useEcho } from '@/Composables/useEcho';
import type { PageProps } from '@/types/api';

/**
 * The agent's battle station.
 *
 * Layout (one screen, no scrolling):
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │ Session status bar (start/pause/stop, counters)                   │
 *   ├──────────────────────────────┬───────────────────────────────────┤
 *   │ Lead info                    │  Call control (timer + buttons)   │
 *   ├──────────────────────────────┼───────────────────────────────────┤
 *   │ Dynamic script               │  Disposition (1-click + shortcuts)│
 *   └──────────────────────────────┴───────────────────────────────────┘
 *
 * State sources:
 *   - REST   for initial session/call snapshot
 *   - Echo   for state transitions (call.initiated → connected → ended)
 *   - Local  for the call timer (composable; no server round-trip)
 */

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);

const dialer = useDialerSession();
const active = useActiveCall();
const { on } = useEcho();

// Wire WebSocket subscriptions (lifetime managed by useEcho)
if (user.value) {
    const channel = `tenant.${user.value.tenant_id}.agent.${user.value.id}`;
    on(channel, 'call.initiated', (p) => active.applyBroadcast('call.initiated', p));
    on(channel, 'call.connected', (p) => active.applyBroadcast('call.connected', p));
    on(channel, 'call.ended', (p) => active.applyBroadcast('call.ended', p));
}

onMounted(async () => {
    await dialer.reload();
    if (user.value) {
        await active.loadActive(user.value.id);
    }
});

async function startSession(): Promise<void> {
    await dialer.start();
}

async function endCall(): Promise<void> {
    await active.endCall();
}

async function applyDisposition(payload: { disposition: string; notes: string | null }): Promise<void> {
    await active.setDisposition(payload.disposition, payload.notes ?? undefined);
    // Auto-clear local state — the next call (or a manual click) populates again.
    if (active.call.value?.status && ['completed', 'busy', 'no_answer', 'failed', 'canceled'].includes(active.call.value.status)) {
        active.clear();
    }
}
</script>

<template>
    <AppLayout title="Dialer Console">
        <div class="flex h-full flex-col bg-dialer-bg">
            <SessionStatusBar
                :session="dialer.session.value"
                :on-start="startSession"
                :on-pause="dialer.pause"
                :on-resume="dialer.resume"
                :on-stop="dialer.stop"
            />

            <div class="grid flex-1 grid-cols-2 gap-4 overflow-hidden p-4">
                <div class="grid grid-rows-[1fr_1fr] gap-4 overflow-hidden">
                    <LeadInfoPanel :lead="active.lead.value" />
                    <ScriptPanel :lead="active.lead.value" />
                </div>
                <div class="grid grid-rows-[auto_1fr] gap-4 overflow-hidden">
                    <CallControlPanel :call="active.call.value" @end="endCall" />
                    <DispositionPanel @disposition="applyDisposition" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
