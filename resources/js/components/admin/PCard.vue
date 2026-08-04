<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        subtitle?: string;
        padding?: boolean;
    }>(),
    { padding: true },
);
</script>

<template>
    <section
        class="min-w-0 rounded-xl border border-[#e3e3e3] bg-white shadow-[0_1px_0_rgba(0,0,0,0.04)] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
    >
        <header
            v-if="title || $slots.actions || subtitle"
            class="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 pb-2"
        >
            <div class="min-w-0">
                <h2
                    v-if="title"
                    class="text-[13px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mt-0.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ subtitle }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>

        <div
            :class="padding ? 'px-4 pt-2 pb-4' : ''"
            :style="!title && padding ? 'padding-top:1rem' : ''"
        >
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t border-[#e3e3e3] px-4 py-3 dark:border-[#3a3a3a]"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
