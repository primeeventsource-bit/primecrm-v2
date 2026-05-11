<script setup lang="ts">
/**
 * Inline-SVG icons used by the war room.
 *
 * The project has no icon dependency installed, so we ship the seven
 * glyphs we actually use rather than pulling in a 50KB pack. All paths
 * are 24×24 with stroke=currentColor — caller controls colour via class.
 *
 * Lucide-derived shapes; redrawn here for self-containment.
 */
defineProps<{
    name:
        | 'headphones'
        | 'whisper'
        | 'barge'
        | 'broadcast'
        | 'alert'
        | 'volume'
        | 'volume-off'
        | 'grid'
        | 'map'
        | 'phone'
        | 'flame';
}>();
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <!-- headphones -->
        <template v-if="name === 'headphones'">
            <path d="M3 14v-2a9 9 0 0 1 18 0v2" />
            <path d="M3 14a2 2 0 0 1 2-2h2v6H5a2 2 0 0 1-2-2v-2z" />
            <path d="M21 14a2 2 0 0 0-2-2h-2v6h2a2 2 0 0 0 2-2v-2z" />
        </template>
        <!-- whisper (message bubble) -->
        <template v-else-if="name === 'whisper'">
            <path d="M21 12a8 8 0 0 1-8 8H7l-4 3v-7a8 8 0 1 1 18-4z" />
            <circle cx="9" cy="12" r="0.6" fill="currentColor" />
            <circle cx="13" cy="12" r="0.6" fill="currentColor" />
            <circle cx="17" cy="12" r="0.6" fill="currentColor" />
        </template>
        <!-- barge (users) -->
        <template v-else-if="name === 'barge'">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="3.5" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </template>
        <!-- broadcast (megaphone) -->
        <template v-else-if="name === 'broadcast'">
            <path d="M3 11v2l11 6V5L3 11z" />
            <path d="M14 9a3 3 0 0 1 0 6" />
            <path d="M7 13v3a2 2 0 0 0 2 2h1" />
        </template>
        <!-- alert triangle -->
        <template v-else-if="name === 'alert'">
            <path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.4 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <circle cx="12" cy="17" r="0.9" fill="currentColor" />
        </template>
        <!-- volume on -->
        <template v-else-if="name === 'volume'">
            <path d="M11 5 6 9H2v6h4l5 4V5z" />
            <path d="M15.5 8.5a5 5 0 0 1 0 7" />
            <path d="M18.5 5.5a9 9 0 0 1 0 13" />
        </template>
        <!-- volume off -->
        <template v-else-if="name === 'volume-off'">
            <path d="M11 5 6 9H2v6h4l5 4V5z" />
            <line x1="22" y1="9" x2="16" y2="15" />
            <line x1="16" y1="9" x2="22" y2="15" />
        </template>
        <!-- grid layout -->
        <template v-else-if="name === 'grid'">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </template>
        <!-- floor map (top-down) -->
        <template v-else-if="name === 'map'">
            <path d="M9 3 3 5v16l6-2 6 2 6-2V3l-6 2-6-2z" />
            <line x1="9" y1="3" x2="9" y2="19" />
            <line x1="15" y1="5" x2="15" y2="21" />
        </template>
        <!-- phone -->
        <template v-else-if="name === 'phone'">
            <path d="M22 16.92V20a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.86 19.86 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3.09a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.36a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
        </template>
        <!-- flame -->
        <template v-else-if="name === 'flame'">
            <path d="M12 2s4 4 4 8a4 4 0 0 1-8 0c0-1.5.5-2.5 1-3.5C7.5 8 6 11 6 14a6 6 0 0 0 12 0c0-5-6-12-6-12z" />
        </template>
    </svg>
</template>
