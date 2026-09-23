<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    store: { name: string; email: string | null; phone: string | null };
    steps: string[];
    immediate: string[];
    kept: string[];
    before: string[];
    request: { subject: string; body: string };
    legal: { slug: string; title: string }[];
}>();

// A prefilled mail draft, so the person who has already uninstalled the app
// does not have to guess which details we need to find their account.
const mailto = computed(() => {
    if (!props.store.email) {
        return null;
    }

    const query = new URLSearchParams({
        subject: props.request.subject,
        body: props.request.body,
    });

    return `mailto:${props.store.email}?${query.toString()}`;
});
</script>

<template>
    <Head>
        <title>{{ `Delete your account — ${store.name}` }}</title>
        <meta
            name="description"
            :content="`How to delete your ${store.name} account, what is removed straight away and what is kept.`"
        />
    </Head>

    <div
        class="min-h-screen bg-[#f7f7f7] text-[#303030] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
    >
        <header
            class="sticky top-0 z-10 border-b border-[#e3e3e3] bg-white/85 backdrop-blur dark:border-[#2a2a2a] dark:bg-[#1a1a1a]/85"
        >
            <div
                class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-5 py-3.5"
            >
                <a
                    href="/"
                    class="text-[13px] font-semibold hover:text-[#00795c] dark:hover:text-[#4fd1ab]"
                >
                    {{ store.name }}
                </a>
                <span
                    class="rounded-full bg-[#f0f0f0] px-2.5 py-1 text-xs font-medium text-[#616161] dark:bg-[#2a2a2a] dark:text-[#b5b5b5]"
                    >Account</span
                >
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-5 pb-16">
            <div
                class="border-b border-[#e3e3e3] py-10 sm:py-14 dark:border-[#2a2a2a]"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[#8a8a8a] uppercase"
                >
                    {{ store.name }}
                </p>
                <h1
                    class="mt-2 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    Delete your account
                </h1>
                <p
                    class="mt-4 max-w-xl text-[15px] leading-relaxed text-[#616161] dark:text-[#b5b5b5]"
                >
                    You can close your {{ store.name }} account yourself, from
                    inside the app, and it takes effect immediately. This page
                    says how, and exactly what happens to your data afterwards.
                </p>
            </div>

            <section class="py-10">
                <h2 class="text-lg font-semibold tracking-tight">In the app</h2>
                <ol class="mt-5 space-y-4">
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        class="flex gap-4"
                    >
                        <span
                            class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-[#00a47c] text-xs font-semibold text-white"
                            >{{ index + 1 }}</span
                        >
                        <p
                            class="text-[15px] leading-relaxed text-[#474747] dark:text-[#b5b5b5]"
                        >
                            {{ step }}
                        </p>
                    </li>
                </ol>

                <div
                    class="mt-7 rounded-xl border border-[#e3e3e3] bg-white px-5 py-5 dark:border-[#2a2a2a] dark:bg-[#232323]"
                >
                    <h3 class="text-[15px] font-semibold">
                        Already uninstalled the app?
                    </h3>
                    <p
                        class="mt-2 text-[15px] leading-relaxed text-[#474747] dark:text-[#b5b5b5]"
                    >
                        Write to us and we will close it for you. Send the mail
                        from the address on the account, or tell us the mobile
                        number it uses, so we can be sure it is yours.
                    </p>
                    <a
                        v-if="mailto"
                        :href="mailto"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-[#303030] px-4 py-2.5 text-[13px] font-medium text-white hover:bg-[#1a1a1a] dark:bg-[#e3e3e3] dark:text-[#1a1a1a] dark:hover:bg-white"
                    >
                        Email {{ store.email }}
                    </a>
                    <p
                        v-if="store.phone"
                        class="mt-3 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                    >
                        Or call
                        <a
                            class="font-medium text-[#00795c] underline dark:text-[#4fd1ab]"
                            :href="`tel:${store.phone.replace(/\s+/g, '')}`"
                            >{{ store.phone }}</a
                        >.
                    </p>
                </div>
            </section>

            <section
                class="grid gap-5 border-t border-[#e3e3e3] py-10 sm:grid-cols-2 dark:border-[#2a2a2a]"
            >
                <div
                    class="rounded-xl border border-[#e3e3e3] bg-white px-5 py-5 dark:border-[#2a2a2a] dark:bg-[#232323]"
                >
                    <h2 class="text-[15px] font-semibold">
                        Removed straight away
                    </h2>
                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="item in immediate"
                            :key="item"
                            class="flex gap-3 text-[14px] leading-relaxed text-[#474747] dark:text-[#b5b5b5]"
                        >
                            <span
                                class="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#00a47c]"
                            />
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <div
                    class="rounded-xl border border-[#e3e3e3] bg-white px-5 py-5 dark:border-[#2a2a2a] dark:bg-[#232323]"
                >
                    <h2 class="text-[15px] font-semibold">Kept, and why</h2>
                    <ul class="mt-4 space-y-3">
                        <li
                            v-for="item in kept"
                            :key="item"
                            class="flex gap-3 text-[14px] leading-relaxed text-[#474747] dark:text-[#b5b5b5]"
                        >
                            <span
                                class="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#8a8a8a]"
                            />
                            {{ item }}
                        </li>
                    </ul>
                </div>
            </section>

            <section
                class="border-t border-[#e3e3e3] py-10 dark:border-[#2a2a2a]"
            >
                <h2 class="text-lg font-semibold tracking-tight">
                    Before you start
                </h2>
                <ul class="mt-5 space-y-4">
                    <li
                        v-for="item in before"
                        :key="item"
                        class="rounded-xl border border-[#f0d9a8] bg-[#fdf6e7] px-5 py-4 text-[14px] leading-relaxed text-[#5c4813] dark:border-[#4a3c17] dark:bg-[#2a2317] dark:text-[#e8d4a3]"
                    >
                        {{ item }}
                    </li>
                </ul>
            </section>

            <footer
                class="border-t border-[#e3e3e3] pt-8 text-sm text-[#616161] dark:border-[#2a2a2a] dark:text-[#b5b5b5]"
            >
                <p>
                    Deleting the account is not the only choice — notification
                    preferences can be turned off one at a time in the app,
                    under Account.
                </p>
                <ul v-if="legal.length" class="mt-5 flex flex-wrap gap-2">
                    <li v-for="page in legal" :key="page.slug">
                        <a
                            :href="`/legal/${page.slug}`"
                            class="inline-block rounded-full border border-[#e3e3e3] bg-white px-3 py-1.5 text-[13px] hover:border-[#00a47c] hover:text-[#00795c] dark:border-[#2a2a2a] dark:bg-[#232323] dark:hover:border-[#4fd1ab] dark:hover:text-[#4fd1ab]"
                            >{{ page.title }}</a
                        >
                    </li>
                </ul>
            </footer>
        </main>
    </div>
</template>
