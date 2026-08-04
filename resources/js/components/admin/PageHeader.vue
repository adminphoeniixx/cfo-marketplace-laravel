<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    subtitle?: string;
    backHref?: string;
}>();
</script>

<template>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-2">
            <Link
                v-if="backHref"
                :href="backHref"
                class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg border border-[#d0d0d0] bg-white text-[#303030] hover:bg-[#f7f7f7] dark:border-[#4a4a4a] dark:bg-[#303030] dark:text-[#e3e3e3]"
                aria-label="Go back"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" class="size-4">
                    <path
                        d="M12.7 4.3a1 1 0 010 1.4L8.4 10l4.3 4.3a1 1 0 01-1.4 1.4l-5-5a1 1 0 010-1.4l5-5a1 1 0 011.4 0z"
                    />
                </svg>
            </Link>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1
                        class="truncate text-xl font-semibold text-[#303030] dark:text-[#e3e3e3]"
                    >
                        {{ title }}
                    </h1>
                    <slot name="badge" />
                </div>
                <p
                    v-if="subtitle"
                    class="mt-0.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ subtitle }}
                </p>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
