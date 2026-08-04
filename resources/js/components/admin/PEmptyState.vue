<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center px-6 py-14 text-center"
    >
        <div
            class="mb-3 flex size-12 items-center justify-center rounded-full bg-[#f1f1f1] text-[#8a8a8a] dark:bg-[#303030]"
        >
            <slot name="icon">
                <svg viewBox="0 0 20 20" fill="currentColor" class="size-6">
                    <path
                        d="M3 5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V5zm2 0v10h10V5H5z"
                    />
                </svg>
            </slot>
        </div>
        <h3
            class="text-[15px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ title }}
        </h3>
        <p
            v-if="description"
            class="mt-1 max-w-md text-[13px] text-[#616161] dark:text-[#b5b5b5]"
        >
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
