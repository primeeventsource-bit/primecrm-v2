<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SideNav from '@/Components/SideNav.vue';
import AgentStatusPill from '@/Components/AgentStatusPill.vue';
import type { PageProps } from '@/types/api';

const props = defineProps<{ title?: string }>();
const page = usePage<PageProps>();

const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);
</script>

<template>
    <Head :title="title" />

    <div class="flex h-screen overflow-hidden bg-slate-50">
        <SideNav />

        <main class="flex flex-1 flex-col overflow-hidden">
            <header class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-3">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-semibold text-slate-900">{{ title ?? 'Prime CRM' }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <AgentStatusPill v-if="user" />
                    <div v-if="user" class="text-sm text-slate-600">
                        <div class="font-medium text-slate-900">{{ user.name }}</div>
                        <div class="text-xs text-slate-500">{{ user.role }}</div>
                    </div>
                </div>
            </header>

            <!-- Flash messaging -->
            <div v-if="flash.success" class="border-b border-emerald-200 bg-emerald-50 px-6 py-2 text-sm text-emerald-800">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="border-b border-red-200 bg-red-50 px-6 py-2 text-sm text-red-800">
                {{ flash.error }}
            </div>

            <div class="flex-1 overflow-auto">
                <slot />
            </div>
        </main>
    </div>
</template>
