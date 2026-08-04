<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps<{
    routeUrl: string;
    filters: Record<string, string | number | null | undefined>;
    tabs?: { value: string; label: string; count?: number }[];
    tabKey?: string;
    searchPlaceholder?: string;
}>();

const search = ref(String(props.filters.search ?? ''));
const key = props.tabKey ?? 'status';
let timer: ReturnType<typeof setTimeout> | undefined;

const apply = (overrides: Record<string, FormDataConvertible> = {}) => {
    const payload: Record<string, FormDataConvertible> = {
        ...props.filters,
        search: search.value,
        ...overrides,
    };

    Object.keys(payload).forEach((k) => {
        if (
            payload[k] === '' ||
            payload[k] === null ||
            payload[k] === undefined
        ) {
            delete payload[k];
        }
    });

    router.get(props.routeUrl, payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => apply({ page: undefined }), 350);
});

const activeTab = (value: string) => String(props.filters[key] ?? '') === value;
</script>

<template>
    <div class="border-b border-[#e3e3e3] dark:border-[#3a3a3a]">
        <div
            v-if="tabs?.length"
            class="flex flex-wrap items-center gap-1 px-2 pt-2"
        >
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                class="rounded-lg px-2.5 py-1 text-[13px] font-medium transition"
                :class="
                    activeTab(tab.value)
                        ? 'bg-[#f1f1f1] text-[#303030] dark:bg-[#303030] dark:text-[#e3e3e3]'
                        : 'text-[#616161] hover:bg-[#f7f7f7] dark:text-[#b5b5b5] dark:hover:bg-[#2a2a2a]'
                "
                @click="
                    apply({ [key]: tab.value || undefined, page: undefined })
                "
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="ml-1 text-[#8a8a8a]"
                    >{{ tab.count }}</span
                >
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2 p-2">
            <div
                class="flex min-w-[200px] flex-1 items-center gap-1.5 rounded-lg border border-[#8a8a8a] bg-white px-2 focus-within:border-[#005bd3] focus-within:ring-2 focus-within:ring-[#005bd3]/40 dark:border-[#616161] dark:bg-[#303030]"
            >
                <svg
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    class="size-4 shrink-0 text-[#616161] dark:text-[#b5b5b5]"
                >
                    <path
                        fill-rule="evenodd"
                        d="M9 3.5a5.5 5.5 0 103.4 9.8l3.4 3.4a1 1 0 001.4-1.4l-3.4-3.4A5.5 5.5 0 009 3.5zM5.5 9a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z"
                        clip-rule="evenodd"
                    />
                </svg>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="searchPlaceholder || 'Search'"
                    class="w-full bg-transparent py-1.5 text-[13px] text-[#303030] outline-none placeholder:text-[#8a8a8a] dark:text-[#e3e3e3]"
                />
            </div>
            <slot :apply="apply" />
        </div>
    </div>
</template>
