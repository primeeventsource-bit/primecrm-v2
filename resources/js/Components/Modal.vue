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
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm"
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
        </Transition>
    </Teleport>
</template>
