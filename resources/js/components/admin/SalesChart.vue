<script setup lang="ts">
import { computed, ref } from 'vue';

const props = defineProps<{
    points: { label: string; value: number }[];
    height?: number;
    currency?: boolean;
}>();

const width = 720;
const height = computed(() => props.height ?? 220);
const padding = { top: 16, right: 12, bottom: 26, left: 48 };
const hovered = ref<number | null>(null);

const max = computed(
    () => Math.max(1, ...props.points.map((p) => p.value)) * 1.15,
);

const innerWidth = computed(() => width - padding.left - padding.right);
const innerHeight = computed(() => height.value - padding.top - padding.bottom);

const x = (index: number) =>
    padding.left +
    (props.points.length <= 1
        ? innerWidth.value / 2
        : (index / (props.points.length - 1)) * innerWidth.value);

const y = (value: number) =>
    padding.top + innerHeight.value - (value / max.value) * innerHeight.value;

const linePath = computed(() =>
    props.points
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(point.value).toFixed(1)}`,
        )
        .join(' '),
);

const areaPath = computed(() => {
    if (!props.points.length) {
        return '';
    }

    const last = x(props.points.length - 1).toFixed(1);
    const baseline = (padding.top + innerHeight.value).toFixed(1);

    return `${linePath.value} L${last},${baseline} L${padding.left},${baseline} Z`;
});

const gridLines = computed(() =>
    [0, 0.25, 0.5, 0.75, 1].map((ratio) => ({
        y: padding.top + innerHeight.value * ratio,
        label: formatValue(max.value * (1 - ratio)),
    })),
);

function formatValue(value: number): string {
    if (value >= 100000) {
        return (props.currency ? '₹' : '') + (value / 100000).toFixed(1) + 'L';
    }

    if (value >= 1000) {
        return (props.currency ? '₹' : '') + (value / 1000).toFixed(1) + 'k';
    }

    return (props.currency ? '₹' : '') + Math.round(value);
}

const tickEvery = computed(() =>
    Math.max(1, Math.ceil(props.points.length / 8)),
);
</script>

<template>
    <div class="w-full overflow-x-auto">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            class="h-auto w-full min-w-[520px]"
            role="img"
        >
            <defs>
                <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
                    <stop
                        offset="0%"
                        stop-color="#005bd3"
                        stop-opacity="0.22"
                    />
                    <stop offset="100%" stop-color="#005bd3" stop-opacity="0" />
                </linearGradient>
            </defs>

            <g>
                <line
                    v-for="line in gridLines"
                    :key="line.y"
                    :x1="padding.left"
                    :x2="width - padding.right"
                    :y1="line.y"
                    :y2="line.y"
                    stroke="currentColor"
                    class="text-[#e3e3e3] dark:text-[#3a3a3a]"
                    stroke-width="1"
                />
                <text
                    v-for="line in gridLines"
                    :key="`t-${line.y}`"
                    :x="padding.left - 8"
                    :y="line.y + 4"
                    text-anchor="end"
                    class="fill-[#8a8a8a] text-[10px]"
                >
                    {{ line.label }}
                </text>
            </g>

            <path :d="areaPath" fill="url(#salesFill)" />
            <path
                :d="linePath"
                fill="none"
                stroke="#005bd3"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />

            <g v-for="(point, index) in points" :key="point.label">
                <circle
                    :cx="x(index)"
                    :cy="y(point.value)"
                    :r="hovered === index ? 5 : 3"
                    fill="#005bd3"
                    stroke="#fff"
                    stroke-width="1.5"
                    class="transition-all"
                />
                <rect
                    :x="x(index) - innerWidth / Math.max(points.length, 1) / 2"
                    :y="padding.top"
                    :width="innerWidth / Math.max(points.length, 1)"
                    :height="innerHeight"
                    fill="transparent"
                    @mouseenter="hovered = index"
                    @mouseleave="hovered = null"
                />
                <text
                    v-if="index % tickEvery === 0"
                    :x="x(index)"
                    :y="height - 8"
                    text-anchor="middle"
                    class="fill-[#8a8a8a] text-[10px]"
                >
                    {{ point.label }}
                </text>
            </g>

            <g v-if="hovered !== null">
                <rect
                    :x="Math.min(Math.max(x(hovered) - 46, 4), width - 96)"
                    :y="Math.max(y(points[hovered].value) - 40, 4)"
                    width="92"
                    height="30"
                    rx="6"
                    fill="#1a1a1a"
                />
                <text
                    :x="Math.min(Math.max(x(hovered) - 46, 4), width - 96) + 46"
                    :y="Math.max(y(points[hovered].value) - 20, 24)"
                    text-anchor="middle"
                    class="fill-white text-[11px] font-medium"
                >
                    {{ points[hovered].label }}:
                    {{ formatValue(points[hovered].value) }}
                </text>
            </g>
        </svg>
    </div>
</template>
