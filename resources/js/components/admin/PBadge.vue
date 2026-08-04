<script setup lang="ts">
import { computed } from 'vue';

type Tone =
    | 'neutral'
    | 'info'
    | 'success'
    | 'warning'
    | 'critical'
    | 'attention'
    | 'new';

const props = withDefaults(defineProps<{ tone?: Tone; dot?: boolean }>(), {
    tone: 'neutral',
});

const tones: Record<Tone, string> = {
    neutral:
        'bg-[#e3e3e3] text-[#303030] dark:bg-[#3a3a3a] dark:text-[#e3e3e3]',
    info: 'bg-[#ebf5ff] text-[#00527c] dark:bg-[#002d47] dark:text-[#a5d3ff]',
    success:
        'bg-[#cdfee1] text-[#0c5132] dark:bg-[#0c5132] dark:text-[#cdfee1]',
    warning:
        'bg-[#ffd6a4] text-[#5e4200] dark:bg-[#5e4200] dark:text-[#ffd6a4]',
    critical:
        'bg-[#ffd6d6] text-[#8e0b21] dark:bg-[#8e0b21] dark:text-[#ffd6d6]',
    attention:
        'bg-[#fff1c9] text-[#5e4200] dark:bg-[#5e4200] dark:text-[#fff1c9]',
    new: 'bg-[#f0f0f0] text-[#616161] ring-1 ring-inset ring-[#d0d0d0] dark:bg-[#303030] dark:text-[#b5b5b5] dark:ring-[#4a4a4a]',
};

const dotColors: Record<Tone, string> = {
    neutral: 'bg-[#8a8a8a]',
    info: 'bg-[#0094d5]',
    success: 'bg-[#29845a]',
    warning: 'bg-[#ffb800]',
    critical: 'bg-[#e51c00]',
    attention: 'bg-[#ffb800]',
    new: 'bg-[#8a8a8a]',
};

const classes = computed(() => tones[props.tone]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-lg px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="classes"
    >
        <span
            v-if="dot"
            class="size-1.5 rounded-full"
            :class="dotColors[tone]"
        />
        <slot />
    </span>
</template>
