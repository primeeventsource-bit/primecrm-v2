<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title?: string;
    maxWidth?: string;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

function close(): void {
    emit('close');
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && props.open) close();
}

watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
    },
);

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <!--
      Teleport without the surrounding <Transition>. The previous
      structure was <Teleport><Transition><div v-if>; Vue 3's
      production build has a known reconciliation edge case where a
      parent re-render during the Transition's leave phase can mark
      the v-if child for re-creation, producing a flicker loop that
      only shows in production (dev's eager re-render hides it).
      Visual fade is now a plain CSS transition on the backdrop +
      a `key="modal-root"` so Vue treats the wrapper as a stable
      identity across parent re-renders.
    -->
    <Teleport to="body">
        <div
            v-if="open"
            key="modal-root"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm modal-enter"
            @click.self="close"
        >
            <div class="flex min-h-full items-start justify-center p-4 sm:p-6">
                <div
                    class="w-full rounded-lg bg-white shadow-xl"
                    :class="maxWidth ?? 'max-w-2xl'"
                >
                    <div v-if="title" class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                        <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
                        <button
                            type="button"
                            class="text-slate-400 hover:text-slate-600"
                            @click="close"
                            aria-label="Close"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="px-5 py-4">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/*
 * One-shot fade-in. No leave animation — when v-if flips to false the
 * element is removed immediately, which is what we want to avoid the
 * production-build leave-phase reconciliation flicker.
 */
.modal-enter {
    animation: modal-fade-in 150ms ease-out;
}
@keyframes modal-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
</style>
