<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Props = {
    variant?: 'primary' | 'secondary' | 'plain' | 'critical' | 'success';
    size?: 'micro' | 'slim' | 'medium' | 'large';
    href?: string;
    /**
     * Render a plain anchor instead of an Inertia link. Needed for anything the
     * browser must handle itself — file downloads, or links off the app — since
     * an Inertia visit expects an Inertia response back.
     */
    external?: boolean;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    loading?: boolean;
    fullWidth?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'secondary',
    size: 'medium',
    type: 'button',
});

const classes = computed(() => {
    const base = [
        'relative inline-flex items-center justify-center gap-1.5 rounded-lg font-medium',
        'transition-colors duration-100 outline-none select-none whitespace-nowrap',
        'focus-visible:ring-2 focus-visible:ring-[#005bd3] focus-visible:ring-offset-1',
        'disabled:cursor-not-allowed disabled:opacity-50',
    ];

    const sizes: Record<string, string> = {
        micro: 'h-7 px-2 text-xs',
        slim: 'h-8 px-3 text-[13px]',
        medium: 'h-8 px-3 text-[13px]',
        large: 'h-10 px-4 text-sm',
    };

    const variants: Record<string, string> = {
        primary:
            'bg-[#303030] text-white shadow-[0_-1px_0_0_#b5b5b5_inset,0_1px_0_0_rgba(255,255,255,0.24)_inset] hover:bg-[#1a1a1a] active:bg-[#000] dark:bg-white dark:text-[#1a1a1a] dark:hover:bg-[#e3e3e3]',
        secondary:
            'bg-white text-[#303030] border border-[#d0d0d0] shadow-[0_1px_0_rgba(0,0,0,0.05)] hover:bg-[#f7f7f7] active:bg-[#f0f0f0] dark:bg-[#303030] dark:text-[#e3e3e3] dark:border-[#4a4a4a] dark:hover:bg-[#3a3a3a]',
        plain: 'bg-transparent text-[#005bd3] hover:bg-[#f1f1f1] hover:underline dark:text-[#8ac1ff] dark:hover:bg-[#303030]',
        critical:
            'bg-[#e51c00] text-white shadow-[0_-1px_0_0_rgba(0,0,0,0.2)_inset] hover:bg-[#c91d00] active:bg-[#a91800]',
        success:
            'bg-[#0c5132] text-white shadow-[0_-1px_0_0_rgba(0,0,0,0.2)_inset] hover:bg-[#0a3f27]',
    };

    return [
        ...base,
        sizes[props.size],
        variants[props.variant],
        props.fullWidth ? 'w-full' : '',
    ].join(' ');
});
</script>

<template>
    <a v-if="href && external" :href="href" :class="classes">
        <slot />
    </a>
    <Link
        v-else-if="href && method && method !== 'get'"
        :href="href"
        :method="method"
        as="button"
        :class="classes"
        :disabled="disabled || loading"
    >
        <slot />
    </Link>
    <Link v-else-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button
        v-else
        :type="type"
        :class="classes"
        :disabled="disabled || loading"
    >
        <svg
            v-if="loading"
            class="size-3.5 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"
            />
        </svg>
        <slot />
    </button>
</template>
