<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';

const props = defineProps<{
    store: {
        name: string | null;
        status: string | null;
        rejection_reason: string | null;
        applied_at: string | null;
    };
    support: {
        email: string | null;
        phone: string | null;
    };
}>();

const headline = () => {
    switch (props.store.status) {
        case 'approved':
            return 'Your store is approved';
        case 'suspended':
            return 'Your store is suspended';
        case 'rejected':
            return 'Your application was not accepted';
        default:
            return 'Your application is with us';
    }
};

const applied = () =>
    props.store.applied_at
        ? new Date(props.store.applied_at).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : null;

const signOut = () => router.post('/logout');
</script>

<template>
    <Head title="Your application" />

    <div
        class="flex min-h-screen items-center justify-center bg-[#f7f7f7] px-5 py-10 text-[#303030] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
    >
        <div
            class="w-full max-w-lg rounded-xl border border-[#e3e3e3] bg-white p-6 shadow-sm dark:border-[#2a2a2a] dark:bg-[#232323]"
        >
            <p class="text-xs font-medium tracking-wide text-[#8a8a8a] uppercase">
                {{ store.name }}
            </p>
            <h1 class="mt-1 text-xl font-semibold">{{ headline() }}</h1>

            <template v-if="store.status === 'approved'">
                <p class="mt-3 text-sm text-[#616161] dark:text-[#b5b5b5]">
                    Everything is open. Add your first products and you can
                    start taking orders.
                </p>
                <a
                    href="/seller/products"
                    class="mt-5 inline-block rounded-lg bg-[#303030] px-4 py-2.5 text-sm font-medium text-white dark:bg-[#e3e3e3] dark:text-[#1a1a1a]"
                >
                    Open the seller panel
                </a>
            </template>

            <template v-else-if="store.status === 'rejected'">
                <p class="mt-3 text-sm text-[#616161] dark:text-[#b5b5b5]">
                    {{ store.rejection_reason ?? 'No reason was recorded.' }}
                </p>
            </template>

            <template v-else-if="store.status === 'suspended'">
                <p class="mt-3 text-sm text-[#616161] dark:text-[#b5b5b5]">
                    Your listings are hidden while this is sorted out. Get in
                    touch and we will go through it with you.
                </p>
            </template>

            <template v-else>
                <p class="mt-3 text-sm text-[#616161] dark:text-[#b5b5b5]">
                    A person reads every application, so this is not instant.
                    You will be emailed as soon as your store is approved — and
                    you can sign in here any time to check.
                </p>
                <p v-if="applied()" class="mt-3 text-sm text-[#8a8a8a]">
                    Applied on {{ applied() }}.
                </p>
            </template>

            <div
                v-if="support.email || support.phone"
                class="mt-6 border-t border-[#f0f0f0] pt-4 text-sm text-[#8a8a8a] dark:border-[#2a2a2a]"
            >
                Need us?
                <template v-if="support.email">
                    <a class="underline" :href="`mailto:${support.email}`">{{ support.email }}</a>
                </template>
                <template v-if="support.email && support.phone"> · </template>
                <template v-if="support.phone">{{ support.phone }}</template>
            </div>

            <button
                class="mt-4 text-sm text-[#8a8a8a] underline"
                type="button"
                @click="signOut"
            >
                Sign out
            </button>
        </div>
    </div>
</template>
