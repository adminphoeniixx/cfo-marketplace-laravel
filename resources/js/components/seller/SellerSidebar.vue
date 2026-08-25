<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useSections } from '@/composables/useSections';

type NavItem = {
    label: string;
    href: string;
    match: string;
    icon: string;
    badge?: number | null;
    /** Role-matrix section this entry needs. Ungated when omitted. */
    section?: string;
};
type NavGroup = { label: string; items: NavItem[] };

const props = withDefaults(
    defineProps<{
        counts?: Record<string, number>;
        /** Whether the persistent desktop column is expanded. */
        desktopVisible?: boolean;
    }>(),
    { desktopVisible: true },
);
const page = usePage();

const icons: Record<string, string> = {
    products:
        'M10 1.8l7 3.5v9.4l-7 3.5-7-3.5V5.3l7-3.5zm0 2.2L5.3 6.3 10 8.6l4.7-2.3L10 4zM4.5 7.8v6.1l4.8 2.4v-6.1L4.5 7.8zm6.7 8.5l4.8-2.4V7.8l-4.8 2.4v6.1z',
    orders: 'M4 3h12a1 1 0 011 1v3H3V4a1 1 0 011-1zm-1 6h14v7a1 1 0 01-1 1H4a1 1 0 01-1-1V9zm4 2v2h6v-2H7z',
    analytics:
        'M3 3h1.5v12.5H17V17H3V3zm3.5 8h2v4h-2v-4zm3.5-3h2v7h-2V8zm3.5-3h2v10h-2V5z',
    settings:
        'M10 6.5A3.5 3.5 0 1010 13.5 3.5 3.5 0 0010 6.5zM8.9 1h2.2l.4 2.1 1.7 1 2-.7 1.1 1.9-1.6 1.4.2 2-.2 2 1.6 1.4-1.1 1.9-2-.7-1.7 1-.4 2.1H8.9l-.4-2.1-1.7-1-2 .7-1.1-1.9 1.6-1.4L5.1 10l-.2-2-1.6-1.4 1.1-1.9 2 .7 1.7-1L8.9 1z',
    bell: 'M10 2a5 5 0 00-5 5v3.2l-1.3 2.3A1 1 0 004.6 14h10.8a1 1 0 00.9-1.5L15 10.2V7a5 5 0 00-5-5zm0 15a2.5 2.5 0 002.4-2h-4.8A2.5 2.5 0 0010 17z',
};

const { sellerSections } = useSections();

const allGroups = computed<NavGroup[]>(() => [
    {
        label: 'Sales',
        items: [
            {
                label: 'Orders',
                href: '/seller/orders',
                match: '/seller/orders',
                icon: 'orders',
                badge: props.counts?.to_pack,
                section: 'orders',
            },
            {
                label: 'Analytics',
                href: '/seller/analytics',
                match: '/seller/analytics',
                icon: 'analytics',
                section: 'analytics',
            },
        ],
    },
    {
        label: 'Catalog',
        items: [
            {
                label: 'Products',
                href: '/seller/products',
                match: '/seller/products',
                icon: 'products',
                badge: props.counts?.low_stock,
                section: 'products',
            },
        ],
    },
    {
        label: 'Account',
        items: [
            {
                label: 'Notifications',
                href: '/seller/notifications',
                match: '/seller/notifications',
                icon: 'bell',
                badge: props.counts?.unread,
            },
            {
                label: 'Store settings',
                href: '/seller/settings',
                match: '/seller/settings',
                icon: 'settings',
            },
        ],
    },
]);

const groups = computed<NavGroup[]>(() =>
    allGroups.value
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    !item.section ||
                    sellerSections.value.includes(item.section),
            ),
        }))
        .filter((group) => group.items.length > 0),
);

const isActive = (item: NavItem) =>
    page.url.split('?')[0].startsWith(item.match);

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col border-r border-[#e1e1e1] bg-[#f1f1f1] pt-[3.5rem] transition-transform duration-200 dark:border-[#2a2a2a] dark:bg-[#161616]"
        :class="[
            open ? 'translate-x-0' : '-translate-x-full',
            desktopVisible
                ? 'lg:sticky lg:top-0 lg:h-screen lg:translate-x-0'
                : 'lg:hidden',
        ]"
    >
        <nav class="flex-1 space-y-4 overflow-y-auto px-2 py-3">
            <div v-for="group in groups" :key="group.label">
                <p
                    v-if="group.label"
                    class="px-2.5 pb-1 text-[11px] font-semibold tracking-wide text-[#8a8a8a] uppercase"
                >
                    {{ group.label }}
                </p>
                <ul class="space-y-0.5">
                    <li v-for="item in group.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="group flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] font-medium transition"
                            :class="
                                isActive(item)
                                    ? 'bg-white text-[#303030] shadow-[0_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#303030] dark:text-white'
                                    : 'text-[#4a4a4a] hover:bg-[#e3e3e3] dark:text-[#b5b5b5] dark:hover:bg-[#232323]'
                            "
                            @click="open = false"
                        >
                            <svg
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                class="size-[18px] shrink-0 opacity-80"
                            >
                                <path :d="icons[item.icon]" />
                            </svg>
                            <span class="flex-1 truncate">{{
                                item.label
                            }}</span>
                            <span
                                v-if="item.badge"
                                class="rounded-full bg-[#ffd6a4] px-1.5 py-0.5 text-[10px] font-semibold text-[#5e4200]"
                            >
                                {{ item.badge }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </nav>

        <div
            class="border-t border-[#e1e1e1] px-3 py-2.5 text-[11px] text-[#8a8a8a] dark:border-[#2a2a2a]"
        >
            Seller panel · v1.0
        </div>
    </aside>

    <div
        v-if="open"
        class="fixed inset-0 z-30 bg-black/40 lg:hidden"
        @click="open = false"
    />
</template>
