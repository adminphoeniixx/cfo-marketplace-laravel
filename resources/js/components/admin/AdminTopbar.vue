<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLogo from '@/components/admin/AppLogo.vue';

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

const search = ref('');

const submitSearch = () => {
    if (search.value.trim()) {
        router.get('/admin/search', { q: search.value.trim() });
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

        <Link href="/admin" class="flex shrink-0 items-center gap-2 pr-2">
            <AppLogo :size="28" />
            <span
                class="hidden text-sm font-semibold tracking-wide whitespace-nowrap sm:block"
                >CFO</span
            >
        </Link>

        <form
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
                    href="/admin/settings"
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
            v-if="menuOpen"
            class="fixed inset-0 -z-10"
            @click="menuOpen = false"
        />
    </header>
</template>
