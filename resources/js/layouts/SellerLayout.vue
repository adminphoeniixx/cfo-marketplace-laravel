<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AdminTopbar from '@/components/admin/AdminTopbar.vue';
import SellerSidebar from '@/components/seller/SellerSidebar.vue';
import { Toaster } from '@/components/ui/sonner';

const STORAGE_KEY = 'seller.sidebar';
const DESKTOP = 1024; // Tailwind's lg breakpoint

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
    () => (page.props.sellerCounts as Record<string, number> | undefined) ?? {},
);

const storeName = computed(
    () => (page.props.store as { name?: string } | undefined)?.name ?? null,
);
</script>

<template>
    <div
        class="min-h-screen bg-[#f1f1f1] text-[#303030] dark:bg-[#0f0f0f] dark:text-[#e3e3e3]"
    >
        <!-- No global search: that controller reads the whole marketplace. -->
        <AdminTopbar
            base="/seller"
            home-href="/seller"
            :search-href="null"
            settings-href="/seller/settings/store"
            @toggle-sidebar="toggleSidebar"
        />

        <div class="flex pt-14">
            <SellerSidebar
                v-model:open="mobileOpen"
                :desktop-visible="desktopVisible"
                :counts="counts"
            />

            <main class="min-w-0 flex-1">
                <div class="mx-auto w-full max-w-[1200px] px-4 py-5 sm:px-6">
                    <p
                        v-if="storeName"
                        class="mb-3 text-[11px] font-semibold tracking-wide text-[#8a8a8a] uppercase"
                    >
                        {{ storeName }}
                    </p>
                    <slot />
                </div>
            </main>
        </div>

        <Toaster position="top-center" />
    </div>
</template>
