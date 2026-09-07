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
    /** Role section this entry needs. Omitted entries are always shown. */
    section?: string;
    /** Entries only the admin role may open, regardless of the matrix. */
    adminOnly?: boolean;
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
    home: 'M10 2.5l7 6V17a1 1 0 01-1 1h-4v-5H8v5H4a1 1 0 01-1-1V8.5l7-6z',
    orders: 'M4 3h12a1 1 0 011 1v3H3V4a1 1 0 011-1zm-1 6h14v7a1 1 0 01-1 1H4a1 1 0 01-1-1V9zm4 2v2h6v-2H7z',
    products:
        'M10 1.8l7 3.5v9.4l-7 3.5-7-3.5V5.3l7-3.5zm0 2.2L5.3 6.3 10 8.6l4.7-2.3L10 4zM4.5 7.8v6.1l4.8 2.4v-6.1L4.5 7.8zm6.7 8.5l4.8-2.4V7.8l-4.8 2.4v6.1z',
    category: 'M3 3h6v6H3V3zm8 0h6v6h-6V3zM3 11h6v6H3v-6zm8 0h6v6h-6v-6z',
    attributes:
        'M3 4a1 1 0 011-1h5l8 8-6 6-8-8V4zm3 1.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z',
    customers:
        'M10 10a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm-6 6.5C4 13.9 6.7 12 10 12s6 1.9 6 4.5V18H4v-1.5z',
    vendors:
        'M3 3h14l-1 4H4L3 3zm1 5h12v8a1 1 0 01-1 1h-3v-5H8v5H5a1 1 0 01-1-1V8z',
    tax: 'M6 2h8a2 2 0 012 2v14l-3-2-3 2-3-2-3 2V4a2 2 0 012-2zm1 4v2h6V6H7zm0 4v2h6v-2H7z',
    shipping:
        'M1 5a1 1 0 011-1h9a1 1 0 011 1v2h2.6a1 1 0 01.8.4l2.4 3.2a1 1 0 01.2.6V14a1 1 0 01-1 1h-1a2.5 2.5 0 01-5 0H8a2.5 2.5 0 01-5 0H2a1 1 0 01-1-1V5zm11 4v3h5v-1.3L14.6 9H12z',
    cancel: 'M10 2a8 8 0 100 16 8 8 0 000-16zM6.5 6.5l7 7m0-7l-7 7',
    refund: 'M10 3a7 7 0 106.3 4h-2.2A5 5 0 1110 5v2.5L14 4l-4-3.5V3z',
    settings:
        'M10 6.5A3.5 3.5 0 1010 13.5 3.5 3.5 0 0010 6.5zM8.9 1h2.2l.4 2.1 1.7 1 2-.7 1.1 1.9-1.6 1.4.2 2-.2 2 1.6 1.4-1.1 1.9-2-.7-1.7 1-.4 2.1H8.9l-.4-2.1-1.7-1-2 .7-1.1-1.9 1.6-1.4L5.1 10l-.2-2-1.6-1.4 1.1-1.9 2 .7 1.7-1L8.9 1z',
    payouts:
        'M2 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm2 1v2h12V6H4zm0 4v5h12v-5H4zm2 2h4v1.5H6V12z',
    analytics:
        'M3 3h1.5v12.5H17V17H3V3zm3.5 8h2v4h-2v-4zm3.5-3h2v7h-2V8zm3.5-3h2v10h-2V5z',
    payments:
        'M3 4h14a1 1 0 011 1v3H2V5a1 1 0 011-1zm-1 6h16v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zm2.5 2v1.5h4V12h-4z',
    team: 'M7 9a3 3 0 100-6 3 3 0 000 6zm7 0a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM1 16.5C1 13.9 3.7 12 7 12s6 1.9 6 4.5V18H1v-1.5zm13.2 1.5v-1.5c0-1.5-.6-2.8-1.6-3.8 2.9.2 5.4 1.9 5.4 4.3V18h-3.8z',
    roles: 'M10 1.5l6.5 2.8v4.4c0 4-2.8 7.6-6.5 8.8-3.7-1.2-6.5-4.8-6.5-8.8V4.3L10 1.5zm-.9 10.9l4.6-4.6-1.3-1.3-3.3 3.3-1.5-1.5-1.3 1.3 2.8 2.8z',
    // A support conversation: a speech bubble with a line of writing in it.
    tickets:
        'M3 4a2 2 0 012-2h10a2 2 0 012 2v7a2 2 0 01-2 2H8l-4 3.5V13a2 2 0 01-1-1.7V4zm3 2v1.5h8V6H6zm0 3.5V11h5V9.5H6',
};

const { sections, isAdmin } = useSections();

const allGroups = computed<NavGroup[]>(() => [
    {
        label: '',
        items: [
            {
                label: 'Dashboard',
                href: '/admin',
                match: '/admin',
                icon: 'home',
            },
            {
                label: 'Analytics',
                href: '/admin/analytics',
                match: '/admin/analytics',
                icon: 'analytics',
                section: 'analytics',
            },
        ],
    },
    {
        label: 'Sales',
        items: [
            {
                label: 'Orders',
                href: '/admin/orders',
                match: '/admin/orders',
                icon: 'orders',
                badge: props.counts?.open_orders,
                section: 'orders',
            },
            {
                label: 'Cancellations',
                href: '/admin/cancellations',
                match: '/admin/cancellations',
                icon: 'cancel',
                badge: props.counts?.pending_cancellations,
                section: 'cancellations',
            },
            {
                label: 'Refunds',
                href: '/admin/refunds',
                match: '/admin/refunds',
                icon: 'refund',
                badge: props.counts?.pending_refunds,
                section: 'refunds',
            },
            {
                label: 'Broadcasts',
                href: '/admin/broadcasts',
                match: '/admin/broadcasts',
                icon: 'notification',
                section: 'customers',
            },
            {
                label: 'Customers',
                href: '/admin/customers',
                match: '/admin/customers',
                icon: 'customers',
                section: 'customers',
            },
        ],
    },
    {
        label: 'Support',
        items: [
            {
                label: 'Tickets',
                href: '/admin/tickets',
                match: '/admin/tickets',
                icon: 'tickets',
                section: 'tickets',
            },
        ],
    },
    {
        label: 'Catalog',
        items: [
            {
                label: 'Products',
                href: '/admin/products',
                match: '/admin/products',
                icon: 'products',
                section: 'products',
            },
            {
                label: 'Categories',
                href: '/admin/categories',
                match: '/admin/categories',
                icon: 'category',
                section: 'categories',
            },
            {
                label: 'Attributes',
                href: '/admin/attributes',
                match: '/admin/attributes',
                icon: 'attributes',
                section: 'attributes',
            },
        ],
    },
    {
        label: 'Partners',
        items: [
            {
                label: 'Vendors',
                href: '/admin/vendors',
                match: '/admin/vendors',
                icon: 'vendors',
                badge: props.counts?.pending_vendors,
                section: 'vendors',
            },
            {
                label: 'Payouts',
                href: '/admin/payouts',
                match: '/admin/payouts',
                icon: 'payouts',
                section: 'payouts',
            },
        ],
    },
    {
        label: 'Settings',
        items: [
            {
                label: 'Payments',
                href: '/admin/payments',
                match: '/admin/payments',
                icon: 'payments',
                section: 'payments',
            },
            {
                label: 'Taxes',
                href: '/admin/taxes',
                match: '/admin/taxes',
                icon: 'tax',
                section: 'taxes',
            },
            {
                label: 'Shipping',
                href: '/admin/shipping',
                match: '/admin/shipping',
                icon: 'shipping',
                section: 'shipping',
            },
            {
                label: 'App content',
                href: '/admin/content',
                match: '/admin/content',
                icon: 'settings',
                section: 'settings',
            },
            {
                label: 'Store settings',
                href: '/admin/settings',
                match: '/admin/settings',
                icon: 'settings',
                section: 'settings',
            },
        ],
    },
    {
        label: 'Staff',
        items: [
            {
                label: 'Team',
                href: '/admin/team',
                match: '/admin/team',
                icon: 'team',
                section: 'team',
            },
            {
                label: 'Roles',
                href: '/admin/roles',
                match: '/admin/roles',
                icon: 'roles',
                adminOnly: true,
            },
        ],
    },
]);

// Hide what the role cannot open — the routes enforce it either way.
const groups = computed<NavGroup[]>(() =>
    allGroups.value
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => {
                if (item.adminOnly) {
                    return isAdmin.value;
                }

                return !item.section || sections.value.includes(item.section);
            }),
        }))
        .filter((group) => group.items.length > 0),
);

const isActive = (item: NavItem) => {
    const url = page.url.split('?')[0];

    return item.match === '/admin'
        ? url === '/admin'
        : url.startsWith(item.match);
};

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
                                class="rounded-full bg-[#e51c00] px-1.5 py-0.5 text-[10px] font-semibold text-white"
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
            CFO Admin · v1.0
        </div>
    </aside>

    <div
        v-if="open"
        class="fixed inset-0 z-30 bg-black/40 lg:hidden"
        @click="open = false"
    />
</template>
