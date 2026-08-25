<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLogo from '@/components/admin/AppLogo.vue';

type NotificationRow = {
    id: string;
    title: string;
    body: string;
    url: string;
    read: boolean;
    created_at: string;
};

// The seller panel reuses this bar, so anything panel-specific is a prop.
const props = withDefaults(
    defineProps<{
        /** Panel prefix the notification endpoints hang off. */
        base?: string;
        /** Where the logo links to. */
        homeHref?: string;
        /** Omit to hide the global search box. */
        searchHref?: string | null;
        /** Omit to hide the "Store settings" item in the user menu. */
        settingsHref?: string | null;
    }>(),
    {
        base: '/admin',
        homeHref: '/admin',
        searchHref: '/admin/search',
        settingsHref: '/admin/settings',
    },
);

const emit = defineEmits<{ toggleSidebar: [] }>();
const page = usePage();
const menuOpen = ref(false);

const user = computed(
    () =>
        (
            page.props.auth as
                { user?: { name: string; email: string } } | undefined
        )?.user,
);
const initials = computed(() =>
    (user.value?.name ?? 'A')
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const notifications = computed(
    () =>
        (page.props.bell as
            { unread: number; recent: NotificationRow[] } | undefined) ?? {
            unread: 0,
            recent: [],
        },
);
const bellOpen = ref(false);

const openNotification = (row: NotificationRow) => {
    bellOpen.value = false;

    if (!row.read) {
        router.post(
            `${props.base}/notifications/${row.id}/read`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    router.visit(row.url);
};

const markAllRead = () =>
    router.post(
        `${props.base}/notifications/read-all`,
        {},
        { preserveScroll: true, onSuccess: () => (bellOpen.value = false) },
    );

const search = ref('');

const submitSearch = () => {
    if (props.searchHref && search.value.trim()) {
        router.get(props.searchHref, { q: search.value.trim() });
    }
};

const logout = () => router.post('/logout');
</script>

<template>
    <header
        class="fixed inset-x-0 top-0 z-50 flex h-14 items-center gap-3 bg-[#1a1a1a] px-3 text-white"
    >
        <button
            type="button"
            class="rounded-lg p-1.5 hover:bg-white/10"
            aria-label="Toggle navigation"
            @click="emit('toggleSidebar')"
        >
            <svg viewBox="0 0 20 20" fill="currentColor" class="size-5">
                <path d="M3 5h14v2H3V5zm0 4h14v2H3V9zm0 4h14v2H3v-2z" />
            </svg>
        </button>

        <Link :href="homeHref" class="flex shrink-0 items-center gap-2 pr-2">
            <AppLogo :size="28" />
            <span
                class="hidden text-sm font-semibold tracking-wide whitespace-nowrap sm:block"
                >CFO</span
            >
        </Link>

        <form
            v-if="searchHref"
            class="mx-auto hidden w-full max-w-xl md:block"
            @submit.prevent="submitSearch"
        >
            <div
                class="flex items-center gap-2 rounded-lg bg-[#303030] px-2.5 ring-1 ring-[#4a4a4a] focus-within:bg-white focus-within:ring-2 focus-within:ring-white"
            >
                <svg
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    class="size-4 shrink-0 text-[#b5b5b5]"
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
                    placeholder="Search orders, products, customers"
                    class="w-full bg-transparent py-1.5 text-[13px] outline-none placeholder:text-[#b5b5b5] focus:text-[#303030]"
                />
            </div>
        </form>

        <div class="relative ml-auto shrink-0">
            <button
                type="button"
                class="relative rounded-lg p-1.5 hover:bg-white/10"
                aria-label="Notifications"
                @click="bellOpen = !bellOpen"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" class="size-5">
                    <path
                        d="M10 2a5 5 0 00-5 5v3.2l-1.3 2.3A1 1 0 004.6 14h10.8a1 1 0 00.9-1.5L15 10.2V7a5 5 0 00-5-5zm0 15a2.5 2.5 0 002.4-2h-4.8A2.5 2.5 0 0010 17z"
                    />
                </svg>
                <span
                    v-if="notifications.unread"
                    class="absolute top-0.5 right-0.5 min-w-[15px] rounded-full bg-[#e51c00] px-1 text-[10px] leading-[15px] font-semibold"
                >
                    {{
                        notifications.unread > 99 ? '99+' : notifications.unread
                    }}
                </span>
            </button>

            <div
                v-if="bellOpen"
                class="absolute top-full right-0 z-10 mt-1 w-80 overflow-hidden rounded-xl border border-[#e3e3e3] bg-white text-[#303030] shadow-xl dark:border-[#3a3a3a] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-[#e3e3e3] px-3 py-2 dark:border-[#3a3a3a]"
                >
                    <p class="text-[13px] font-semibold">Notifications</p>
                    <button
                        v-if="notifications.unread"
                        type="button"
                        class="text-xs text-[#005bd3] hover:underline dark:text-[#a5d3ff]"
                        @click="markAllRead"
                    >
                        Mark all read
                    </button>
                </div>

                <ul
                    v-if="notifications.recent.length"
                    class="max-h-80 overflow-y-auto"
                >
                    <li
                        v-for="row in notifications.recent"
                        :key="row.id"
                        class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
                    >
                        <button
                            type="button"
                            class="flex w-full gap-2 px-3 py-2 text-left hover:bg-[#f1f1f1] dark:hover:bg-[#303030]"
                            @click="openNotification(row)"
                        >
                            <span
                                class="mt-1.5 size-1.5 shrink-0 rounded-full"
                                :class="
                                    row.read
                                        ? 'bg-transparent'
                                        : 'bg-[#005bd3] dark:bg-[#a5d3ff]'
                                "
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13px]"
                                    :class="row.read ? '' : 'font-semibold'"
                                    >{{ row.title }}</span
                                >
                                <span
                                    class="block truncate text-xs text-[#616161] dark:text-[#b5b5b5]"
                                    >{{ row.body }}</span
                                >
                            </span>
                        </button>
                    </li>
                </ul>

                <p
                    v-else
                    class="px-3 py-6 text-center text-[13px] text-[#8a8a8a]"
                >
                    Nothing yet.
                </p>

                <Link
                    :href="`${base}/notifications`"
                    class="block border-t border-[#e3e3e3] px-3 py-2 text-center text-[13px] font-medium hover:bg-[#f1f1f1] dark:border-[#3a3a3a] dark:hover:bg-[#303030]"
                    @click="bellOpen = false"
                >
                    View all
                </Link>
            </div>
        </div>

        <div class="relative shrink-0">
            <button
                type="button"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-white/10"
                @click="menuOpen = !menuOpen"
            >
                <span
                    class="hidden max-w-[10rem] truncate text-[13px] font-medium whitespace-nowrap sm:block"
                    >{{ user?.name ?? 'Admin' }}</span
                >
                <span
                    class="flex size-7 items-center justify-center rounded-lg bg-[#36fba1] text-xs font-bold text-[#0c5132]"
                >
                    {{ initials }}
                </span>
            </button>

            <div
                v-if="menuOpen"
                class="absolute top-full right-0 mt-1 w-56 overflow-hidden rounded-xl border border-[#e3e3e3] bg-white py-1 text-[#303030] shadow-xl dark:border-[#3a3a3a] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
                @click="menuOpen = false"
            >
                <div
                    class="border-b border-[#e3e3e3] px-3 py-2 dark:border-[#3a3a3a]"
                >
                    <p class="truncate text-[13px] font-semibold">
                        {{ user?.name }}
                    </p>
                    <p
                        class="truncate text-xs text-[#616161] dark:text-[#b5b5b5]"
                    >
                        {{ user?.email }}
                    </p>
                </div>
                <Link
                    href="/settings/profile"
                    class="block px-3 py-1.5 text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#303030]"
                >
                    Profile
                </Link>
                <Link
                    v-if="settingsHref"
                    :href="settingsHref"
                    class="block px-3 py-1.5 text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#303030]"
                >
                    Store settings
                </Link>
                <button
                    type="button"
                    class="block w-full px-3 py-1.5 text-left text-[13px] text-[#e51c00] hover:bg-[#f1f1f1] dark:hover:bg-[#303030]"
                    @click="logout"
                >
                    Log out
                </button>
            </div>
        </div>

        <div
            v-if="menuOpen || bellOpen"
            class="fixed inset-0 -z-10"
            @click="
                menuOpen = false;
                bellOpen = false;
            "
        />
    </header>
</template>
