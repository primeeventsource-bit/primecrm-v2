<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import CreateAgentForm from '@/Components/Agents/CreateAgentForm.vue';
import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types/api';

interface Agent {
    id: string;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string;
    role: string;
    phone: string | null;
    extension: string | null;
    timezone: string | null;
    skills: string[];
    is_panama_based: boolean;
    created_at: string | null;
}

const page = usePage<PageProps>();
const isSupervisor = computed(() => {
    const role = page.props.auth.user?.role;
    return ['master_admin', 'admin', 'supervisor', 'manager'].includes(role ?? '');
});

const agents = ref<Agent[]>([]);
const total = ref(0);
const search = ref('');
const role = ref('');
const location = ref<'all' | 'us' | 'panama'>('all');
const loading = ref(false);
const createOpen = ref(false);
let timer: number | undefined;

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params: Record<string, string | number | boolean> = { per_page: 100 };
        if (search.value) params.q = search.value;
        if (role.value) params.role = role.value;
        if (location.value === 'us') params.is_panama_based = false;
        if (location.value === 'panama') params.is_panama_based = true;
        const { data } = await axios.get<{ data: Agent[]; meta: { total: number } }>('/api/agents', { params });
        agents.value = data.data;
        total.value = data.meta.total;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = window.setTimeout(load, 250);
});
watch([role, location], () => void load());

function roleClass(r: string): string {
    return {
        master_admin: 'bg-purple-100 text-purple-700',
        admin: 'bg-purple-100 text-purple-700',
        supervisor: 'bg-blue-100 text-blue-700',
        manager: 'bg-blue-100 text-blue-700',
        qa: 'bg-amber-100 text-amber-700',
        closer: 'bg-emerald-100 text-emerald-700',
        fronter: 'bg-cyan-100 text-cyan-700',
        agent: 'bg-slate-100 text-slate-700',
    }[r] ?? 'bg-slate-100 text-slate-700';
}

function onCreated(): void {
    createOpen.value = false;
    void load();
}

onMounted(load);
</script>

<template>
    <AppLayout title="Sales Agents">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Sales agents</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ total }} total · closers, fronters, supervisors</p>
                </div>
                <button v-if="isSupervisor" class="btn-primary" @click="createOpen = true">+ Add agent</button>
            </div>

            <Modal :open="createOpen" title="Add an agent" @close="createOpen = false">
                <CreateAgentForm @created="onCreated" @cancel="createOpen = false" />
            </Modal>

            <section class="panel mb-4 grid grid-cols-1 gap-3 p-3 md:grid-cols-4">
                <div>
                    <label class="label">Search</label>
                    <input v-model="search" type="text" placeholder="name / email" class="input mt-1" />
                </div>
                <div>
                    <label class="label">Role</label>
                    <select v-model="role" class="input mt-1">
                        <option value="">All</option>
                        <option value="master_admin">Master admin</option>
                        <option value="admin">Admin</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="manager">Manager</option>
                        <option value="closer">Closer</option>
                        <option value="fronter">Fronter</option>
                        <option value="agent">Agent</option>
                        <option value="qa">QA</option>
                    </select>
                </div>
                <div>
                    <label class="label">Location</label>
                    <select v-model="location" class="input mt-1">
                        <option value="all">All</option>
                        <option value="us">US</option>
                        <option value="panama">Panama</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="btn-ghost w-full text-slate-600 hover:bg-slate-100" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
                </div>
            </section>

            <div class="panel overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Name</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Email</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Role</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Location</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Ext.</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-slate-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!loading && agents.length === 0">
                            <td colspan="6" class="px-3 py-12 text-center text-sm text-slate-500">
                                No agents match those filters.
                                <span v-if="isSupervisor"> Click <b>+ Add agent</b> to create one.</span>
                            </td>
                        </tr>
                        <tr v-for="a in agents" :key="a.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2 text-sm text-slate-900">{{ a.full_name }}</td>
                            <td class="px-3 py-2 text-sm text-slate-700">{{ a.email }}</td>
                            <td class="px-3 py-2"><span class="pill" :class="roleClass(a.role)">{{ a.role.replace(/_/g, ' ') }}</span></td>
                            <td class="px-3 py-2 text-sm text-slate-600">{{ a.is_panama_based ? '🇵🇦 Panama' : '🇺🇸 US' }}</td>
                            <td class="px-3 py-2 text-sm font-mono text-slate-700">{{ a.extension ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ a.created_at?.split('T')[0] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
