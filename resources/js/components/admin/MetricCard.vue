<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    change?: number | null;
    caption?: string;
}>();
</script>

<template>
    <div
        class="rounded-xl border border-[#e3e3e3] bg-white p-4 shadow-[0_1px_0_rgba(0,0,0,0.04)] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
    >
        <p class="text-[13px] font-medium text-[#616161] dark:text-[#b5b5b5]">
            {{ label }}
        </p>
        <p
            class="mt-1 text-2xl font-semibold tracking-tight text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ value }}
        </p>
        <div class="mt-1 flex items-center gap-1.5">
            <span
                v-if="change !== null && change !== undefined"
                class="inline-flex items-center gap-0.5 text-xs font-medium"
                :class="
                    change >= 0
                        ? 'text-[#0c5132] dark:text-[#8ce0b3]'
                        : 'text-[#e51c00]'
                "
            >
                <svg
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    class="size-3"
                    :class="change >= 0 ? '' : 'rotate-180'"
                >
                    <path d="M10 4l6 8H4l6-8z" />
                </svg>
                {{ Math.abs(change).toFixed(1) }}%
            </span>
            <span v-if="caption" class="text-xs text-[#8a8a8a]">{{
                caption
            }}</span>
        </div>
    </div>
</template>
