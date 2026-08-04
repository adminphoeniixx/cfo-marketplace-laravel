<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    from?: number | null;
    to?: number | null;
    total?: number;
}>();
</script>

<template>
    <div
        v-if="links.length > 3"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e3e3e3] px-4 py-3 dark:border-[#3a3a3a]"
    >
        <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
            Showing <span class="font-medium">{{ from ?? 0 }}</span
            >–<span class="font-medium">{{ to ?? 0 }}</span> of
            <span class="font-medium">{{ total ?? 0 }}</span>
        </p>
        <nav class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <span
                    v-if="!link.url"
                    class="cursor-not-allowed rounded-lg px-2.5 py-1 text-xs text-[#b5b5b5]"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="rounded-lg px-2.5 py-1 text-xs transition"
                    :class="
                        link.active
                            ? 'bg-[#303030] text-white dark:bg-white dark:text-[#1a1a1a]'
                            : 'text-[#303030] hover:bg-[#f1f1f1] dark:text-[#e3e3e3] dark:hover:bg-[#303030]'
                    "
                >
                    <!-- Laravel's paginator labels carry entities (&laquo; etc.),
                         so they need v-html — on a plain element, not the component. -->
                    <span v-html="link.label" />
                </Link>
            </template>
        </nav>
    </div>
</template>
