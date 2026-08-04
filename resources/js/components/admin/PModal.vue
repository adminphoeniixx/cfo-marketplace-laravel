<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title: string;
    size?: 'small' | 'medium' | 'large';
}>();
const emit = defineEmits<{ close: [] }>();

// `<Teleport to="body">` has no equivalent on the server, so rendering it during
// SSR leaves a node where the client expects the v-if placeholder and hydration
// mismatches. Modals are never open on first paint, so mount them client-side.
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
});

const onKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        emit('close');
    }
};

watch(
    () => props.open,
    (open) => {
        if (typeof document === 'undefined') {
            return;
        }

        document.body.style.overflow = open ? 'hidden' : '';

        if (open) {
            window.addEventListener('keydown', onKey);
        } else {
            window.removeEventListener('keydown', onKey);
        }
    },
);

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
    }

    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport v-if="mounted" to="body">
        <Transition
            enter-active-class="transition duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8"
            >
                <div class="absolute inset-0" @click="emit('close')" />
                <div
                    class="relative z-10 w-full rounded-xl bg-white shadow-2xl dark:bg-[#1a1a1a]"
                    :class="{
                        'max-w-md': size === 'small',
                        'max-w-lg': !size || size === 'medium',
                        'max-w-3xl': size === 'large',
                    }"
                >
                    <header
                        class="flex items-center justify-between border-b border-[#e3e3e3] px-4 py-3 dark:border-[#3a3a3a]"
                    >
                        <h2
                            class="text-sm font-semibold text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ title }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#303030]"
                            @click="emit('close')"
                        >
                            <svg
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                class="size-4"
                            >
                                <path
                                    d="M4.3 4.3a1 1 0 011.4 0L10 8.6l4.3-4.3a1 1 0 111.4 1.4L11.4 10l4.3 4.3a1 1 0 01-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 01-1.4-1.4L8.6 10 4.3 5.7a1 1 0 010-1.4z"
                                />
                            </svg>
                        </button>
                    </header>
                    <div class="max-h-[70vh] overflow-y-auto px-4 py-4">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="flex items-center justify-end gap-2 border-t border-[#e3e3e3] px-4 py-3 dark:border-[#3a3a3a]"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
