<script setup lang="ts">
/**
 * CFO brand mark: a rounded tile holding an open "C" with a rising trend line
 * in its mouth — finance plus growth, legible down to 20px.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 28 });
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 32 32"
        fill="none"
        role="img"
        aria-label="CFO"
    >
        <rect width="32" height="32" rx="9" fill="url(#cfo-tile)" />
        <path
            d="M20.4 11.5a6.4 6.4 0 1 0 0 9"
            stroke="#fff"
            stroke-width="2.9"
            stroke-linecap="round"
        />
        <path
            d="m18.8 18.6 2.6-3.2 2 1.7 3-3.8"
            stroke="#fff"
            stroke-width="2.1"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.95"
        />
        <defs>
            <linearGradient
                id="cfo-tile"
                x1="0"
                y1="0"
                x2="32"
                y2="32"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#00c48c" />
                <stop offset="1" stop-color="#00795c" />
            </linearGradient>
        </defs>
    </svg>
</template>
