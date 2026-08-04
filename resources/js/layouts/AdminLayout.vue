<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '@/components/admin/AdminSidebar.vue';
import AdminTopbar from '@/components/admin/AdminTopbar.vue';
import { Toaster } from '@/components/ui/sonner';

const STORAGE_KEY = 'admin.sidebar';
const DESKTOP = 1024; // Tailwind's lg breakpoint

// Two separate states: on small screens the sidebar is an overlay that starts
// closed, on large screens it is a persistent column the user can collapse.
// Both start at their SSR value so hydration matches; the stored desktop
// preference is applied once mounted.
const mobileOpen = ref(false);
const desktopVisible = ref(true);

onMounted(() => {
    desktopVisible.value = localStorage.getItem(STORAGE_KEY) !== 'collapsed';
});

const toggleSidebar = () => {
    if (window.innerWidth >= DESKTOP) {
        desktopVisible.value = !desktopVisible.value;
        localStorage.setItem(
            STORAGE_KEY,
            desktopVisible.value ? 'expanded' : 'collapsed',
        );

        return;
    }

    mobileOpen.value = !mobileOpen.value;
};

const page = usePage();

const counts = computed(
    () => (page.props.adminCounts as Record<string, number> | undefined) ?? {},
);
</script>

<template>
    <div
        class="min-h-screen bg-[#f1f1f1] text-[#303030] dark:bg-[#0f0f0f] dark:text-[#e3e3e3]"
    >
        <AdminTopbar @toggle-sidebar="toggleSidebar" />

        <div class="flex pt-14">
            <AdminSidebar
                v-model:open="mobileOpen"
                :desktop-visible="desktopVisible"
                :counts="counts"
            />

            <main class="min-w-0 flex-1">
                <div class="mx-auto w-full max-w-[1200px] px-4 py-5 sm:px-6">
                    <slot />
                </div>
            </main>
        </div>

        <Toaster position="top-center" />
    </div>
</template>
