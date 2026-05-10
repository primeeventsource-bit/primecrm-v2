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

interface NavSection {
    label: string;
    items: NavItem[];
}

// Grouped per Floor OS: Operations is the day-to-day floor work
// (closers/fronters live here); Management is the supervisor-leaning
// reporting / governance set.
const sections = computed<NavSection[]>(() => [
    {
        label: 'Operations',
        items: [
            { href: '/dashboard', label: 'Dashboard', icon: '◉', show: true },
            { href: '/dialer/console', label: 'Dialer', icon: '☎', show: true },
            { href: '/leads', label: 'Leads', icon: '◐', show: true },
            { href: '/customers', label: 'Customers', icon: '◍', show: true },
            { href: '/listings', label: 'Listings', icon: '▣', show: true },
            { href: '/pipeline', label: 'Pipeline', icon: '▤', show: true },
            { href: '/booking/search', label: 'Inventory', icon: '◫', show: true },
        ],
    },
    {
        label: 'Management',
        items: [
            { href: '/agents', label: 'Agents', icon: '◊', show: true },
            { href: '/supervisor/war-room', label: 'War Room', icon: '⌖', show: isSupervisor.value },
            { href: '/commission/payouts', label: 'Payouts', icon: '$', show: true },
            { href: '/partner-sites', label: 'Partner sites', icon: '◇', show: isSupervisor.value },
            { href: '/compliance/dnc', label: 'Compliance', icon: '⊘', show: isSupervisor.value },
        ],
    },
]);

const visibleSections = computed(() =>
    sections.value
        .map((s) => ({ ...s, items: s.items.filter((i) => i.show) }))
        .filter((s) => s.items.length > 0)
);

const currentPath = computed(() => window.location.pathname);
function isActive(href: string): boolean {
    return currentPath.value.startsWith(href);
}
</script>

<template>
    <aside class="flex w-56 flex-col border-r border-deck-line bg-deck-surface text-deck-soft">
        <!-- Brand -->
        <div class="border-b border-deck-line px-4 py-4">
            <div class="flex items-center gap-2">
                <span class="inline-block h-2 w-2 rounded-sm bg-floor-accent"></span>
                <div class="text-base font-bold tracking-tight text-deck-text">PRIME CRM</div>
            </div>
            <div class="text-[10px] font-mono uppercase tracking-[0.18em] text-deck-dim mt-0.5">Floor OS · v1</div>
        </div>

        <!-- Sections -->
        <nav class="flex-1 overflow-y-auto px-2 py-4">
            <div v-for="section in visibleSections" :key="section.label" class="mb-5">
                <div class="px-3 mb-1.5 text-[10px] font-semibold font-mono uppercase tracking-[0.18em] text-deck-dim">
                    {{ section.label }}
                </div>
                <Link
                    v-for="item in section.items"
                    :key="item.href"
                    :href="item.href"
                    class="group mb-0.5 flex items-center gap-3 rounded-md px-3 py-1.5 text-sm transition-colors"
                    :class="
                        isActive(item.href)
                            ? 'bg-deck-raised text-deck-text border-l-2 border-floor-accent pl-[10px]'
                            : 'text-deck-soft hover:bg-deck-raised hover:text-deck-text'
                    "
                >
                    <span
                        class="w-4 text-center text-base"
                        :class="isActive(item.href) ? 'text-floor-accent' : 'text-deck-dim group-hover:text-deck-soft'"
                    >{{ item.icon }}</span>
                    <span>{{ item.label }}</span>
                </Link>
            </div>
        </nav>

        <!-- Sign out -->
        <div class="border-t border-deck-line p-3">
            <Link
                href="/api/auth/logout"
                method="post"
                as="button"
                class="w-full rounded-md px-3 py-1.5 text-left text-sm text-deck-soft hover:bg-deck-raised hover:text-deck-text"
            >
                Sign out
            </Link>
        </div>
    </aside>
</template>
