<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

const tiles: Array<{ href: string; title: string; subtitle: string }> = [
    { href: '/dialer/console', title: 'Dialer Console', subtitle: 'Start a session, take and place calls' },
    { href: '/leads', title: 'Leads', subtitle: 'Browse and manage your assigned leads' },
    { href: '/pipeline', title: 'Pipeline', subtitle: 'Drag deals through stages' },
    { href: '/booking/search', title: 'Inventory Search', subtitle: 'Hold and book vacation weeks' },
    { href: '/supervisor/war-room', title: 'War Room', subtitle: 'Live agent presence + call feed' },
    { href: '/commission/payouts', title: 'Payouts', subtitle: 'Period rollups + earnings' },
];
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="p-6">
            <h2 class="text-2xl font-semibold text-slate-900">Welcome back</h2>
            <p class="mt-1 text-sm text-slate-500">Pick where to start.</p>

            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="tile in tiles"
                    :key="tile.href"
                    :href="tile.href"
                    class="panel p-5 transition-shadow hover:shadow-md"
                >
                    <div class="text-base font-semibold text-slate-900">{{ tile.title }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ tile.subtitle }}</div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
