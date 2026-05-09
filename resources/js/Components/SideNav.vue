<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types/api';

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);

const isSupervisor = computed(() => {
    if (!user.value) return false;
    return ['master_admin', 'admin', 'supervisor', 'manager'].includes(user.value.role);
});

interface NavItem {
    href: string;
    label: string;
    icon: string;
    show: boolean;
}

const navItems = computed<NavItem[]>(() => [
    { href: '/dashboard', label: 'Dashboard', icon: '◉', show: true },
    { href: '/dialer/console', label: 'Dialer', icon: '☎', show: true },
    { href: '/leads', label: 'Leads', icon: '◐', show: true },
    { href: '/pipeline', label: 'Pipeline', icon: '▤', show: true },
    { href: '/booking/search', label: 'Inventory', icon: '◫', show: true },
    { href: '/supervisor/war-room', label: 'War Room', icon: '⌖', show: isSupervisor.value },
    { href: '/commission/payouts', label: 'Payouts', icon: '$', show: true },
    { href: '/compliance/dnc', label: 'Compliance', icon: '⊘', show: isSupervisor.value },
]);

const visibleItems = computed(() => navItems.value.filter((i) => i.show));
const currentPath = computed(() => window.location.pathname);

function isActive(href: string): boolean {
    return currentPath.value.startsWith(href);
}
</script>

<template>
    <aside class="flex w-56 flex-col border-r border-slate-200 bg-slate-900 text-slate-300">
        <div class="border-b border-slate-800 px-4 py-4">
            <div class="text-base font-bold text-white">Prime CRM</div>
            <div class="text-xs text-slate-500">Call Center Platform</div>
        </div>

        <nav class="flex-1 overflow-y-auto px-2 py-3">
            <Link
                v-for="item in visibleItems"
                :key="item.href"
                :href="item.href"
                class="mb-1 flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors"
                :class="
                    isActive(item.href)
                        ? 'bg-slate-800 text-white'
                        : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
                "
            >
                <span class="w-4 text-center text-base">{{ item.icon }}</span>
                <span>{{ item.label }}</span>
            </Link>
        </nav>

        <div class="border-t border-slate-800 p-3">
            <Link
                href="/api/auth/logout"
                method="post"
                as="button"
                class="w-full rounded-md px-3 py-2 text-left text-sm text-slate-400 hover:bg-slate-800/60 hover:text-white"
            >
                Sign out
            </Link>
        </div>
    </aside>
</template>
