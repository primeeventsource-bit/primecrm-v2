<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AgentTile from '@/Components/Supervisor/AgentTile.vue';
import LiveCallsFeed from '@/Components/Supervisor/LiveCallsFeed.vue';
import MetricsRibbon from '@/Components/Supervisor/MetricsRibbon.vue';
import AlertsPanel from '@/Components/Supervisor/AlertsPanel.vue';
import { useSupervisorChannel } from '@/Composables/useSupervisorChannel';
import type { AgentStatusValue, PageProps } from '@/types/api';

const page = usePage<PageProps>();
const tenantId = computed(() => page.props.auth.user?.tenant_id ?? '');

const { agents, liveCallsFeed, alerts } = useSupervisorChannel(tenantId.value);

const callsToday = ref(0);
const revenueToday = ref(0);
const conversionPct = ref(0);

interface AgentRecord {
    agent_id: string;
    status: AgentStatusValue;
    current_call_id: string | null;
    last_heartbeat_at: string | null;
}

async function loadInitial(): Promise<void> {
    const { data } = await axios.get<{ data: AgentRecord[] }>('/api/agent-status');
    for (const a of data.data) {
        agents[a.agent_id] = {
            agent_id: a.agent_id,
            status: a.status,
            current_call_id: a.current_call_id,
            last_heartbeat_at: a.last_heartbeat_at ?? null,
        };
    }
}

async function whisper(callId: string): Promise<void> {
    await axios.post(`/api/supervisor/calls/${callId}/whisper`);
}

async function killCall(callId: string): Promise<void> {
    if (!window.confirm('Kill this call?')) return;
    await axios.post(`/api/supervisor/calls/${callId}/kill`);
}

onMounted(loadInitial);
</script>

<template>
    <AppLayout title="Supervisor War Room">
        <div class="flex h-full flex-col gap-4 bg-dialer-bg p-4">
            <MetricsRibbon
                :agents="agents"
                :calls-today="callsToday"
                :revenue-today="revenueToday"
                :conversion-pct="conversionPct"
            />

            <div class="grid flex-1 grid-cols-3 gap-4 overflow-hidden">
                <section class="dialer-panel flex h-full flex-col">
                    <header class="border-b border-slate-700/40 px-4 py-2">
                        <h2 class="text-xs uppercase tracking-wider text-slate-400">
                            Agents ({{ Object.keys(agents).length }})
                        </h2>
                    </header>
                    <div class="grid flex-1 grid-cols-2 gap-2 overflow-y-auto p-3">
                        <AgentTile
                            v-for="agent in agents"
                            :key="agent.agent_id"
                            :agent-id="agent.agent_id"
                            :status="agent.status"
                            :call-id="agent.current_call_id"
                            @whisper="whisper"
                            @kill="killCall"
                        />
                    </div>
                </section>

                <LiveCallsFeed :feed="liveCallsFeed" />
                <AlertsPanel :alerts="alerts" />
            </div>
        </div>
    </AppLayout>
</template>
