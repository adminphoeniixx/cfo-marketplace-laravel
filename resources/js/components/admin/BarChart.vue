<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    points: { label: string; value: number }[];
    currency?: boolean;
}>();

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)));

const format = (value: number) => {
    if (value >= 100000) {
        return (props.currency ? '₹' : '') + (value / 100000).toFixed(1) + 'L';
    }

    if (value >= 1000) {
        return (props.currency ? '₹' : '') + (value / 1000).toFixed(1) + 'k';
    }

    return (props.currency ? '₹' : '') + Math.round(value);
};
</script>

<template>
    <ul class="space-y-2.5">
        <li
            v-for="point in points"
            :key="point.label"
            class="flex items-center gap-3"
        >
            <span
                class="w-28 shrink-0 truncate text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                :title="point.label"
            >
                {{ point.label }}
            </span>
            <span
                class="h-2 flex-1 overflow-hidden rounded-full bg-[#f1f1f1] dark:bg-[#303030]"
            >
                <span
                    class="block h-full rounded-full bg-[#005bd3] transition-all"
                    :style="{
                        width: `${Math.max(2, (point.value / max) * 100)}%`,
                    }"
                />
            </span>
            <span
                class="w-16 shrink-0 text-right text-[13px] font-medium text-[#303030] tabular-nums dark:text-[#e3e3e3]"
            >
                {{ format(point.value) }}
            </span>
        </li>
    </ul>
</template>
