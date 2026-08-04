<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';

const page = usePage();
const user = computed(
    () => (page.props.auth as { user?: { name: string } } | undefined)?.user,
);

const modules = [
    {
        name: 'Catalog',
        body: 'Products with variants, attributes, images and per-vendor stock. Bulk activate, draft or archive straight from the list.',
        path: 'M10 1.5l7 3.5v10l-7 3.5-7-3.5v-10l7-3.5zm0 2.2L5.5 6 10 8.3 14.5 6 10 3.7zM4.5 7.4v6.7l4.8 2.4v-6.7L4.5 7.4zm11 0l-4.8 2.4v6.7l4.8-2.4V7.4z',
    },
    {
        name: 'Orders',
        body: 'One timeline per order: status, payment, partial fulfilment with tracking, and every change logged with who made it.',
        path: 'M5 2h10a1 1 0 011 1v14a1 1 0 01-1.5.9L13 17l-1.5.9a1 1 0 01-1 0L9 17l-1.5.9a1 1 0 01-1 0L5 17l-1.5.9A1 1 0 012 17V3a1 1 0 011-1h2zm1.5 4a.75.75 0 000 1.5h7a.75.75 0 000-1.5h-7zm0 3.5a.75.75 0 000 1.5h7a.75.75 0 000-1.5h-7z',
    },
    {
        name: 'Vendors',
        body: 'Approve or reject applications, set a percentage or flat commission, and suspend a store to unpublish its products at once.',
        path: 'M3 4h14l-.8 3.2a2.2 2.2 0 01-4.3.3 2.2 2.2 0 01-3.8 0 2.2 2.2 0 01-4.3-.3L3 4zm.5 5.6a3.7 3.7 0 003.2-.8 3.7 3.7 0 006.6 0 3.7 3.7 0 003.2.8V16a1 1 0 01-1 1h-3v-4H7.5v4h-3a1 1 0 01-1-1V9.6z',
    },
    {
        name: 'Cancellations & refunds',
        body: 'Item-level requests with prorated amounts, an approve or reject review step, and optional restock when the money goes back.',
        path: 'M10 3a7 7 0 016.9 8.2.75.75 0 11-1.5-.3A5.5 5.5 0 106.6 6.1l1.5 1.4H4.3V3.8l1.2 1.2A7 7 0 0110 3zm-6.4 5.6a.75.75 0 01.9.6 5.5 5.5 0 008.9 3.2l-1.6-1.5h3.9v3.7l-1.2-1.2A7 7 0 013 9.5a.75.75 0 01.6-.9z',
    },
    {
        name: 'Payouts',
        body: 'Generate a settlement for any period: gross sales minus commission, adjustments applied, then mark it paid with a reference.',
        path: 'M2.5 5.5A1.5 1.5 0 014 4h12a1.5 1.5 0 011.5 1.5V7h-15V5.5zM17.5 8.5v6A1.5 1.5 0 0116 16H4a1.5 1.5 0 01-1.5-1.5v-6h15zM12 12.5a.75.75 0 000 1.5h2.5a.75.75 0 000-1.5H12z',
    },
    {
        name: 'Tax & shipping',
        body: 'Tax classes with layered rates per state, plus shipping zones and flat, weight, price or item-based rates — global or per vendor.',
        path: 'M1.5 6A1.5 1.5 0 013 4.5h7A1.5 1.5 0 0111.5 6v1h2.1a1.5 1.5 0 011.2.6l1.9 2.5a1.5 1.5 0 01.3.9V13a1.5 1.5 0 01-1.5 1.5h-.6a2.2 2.2 0 01-4.3 0H8.4a2.2 2.2 0 01-4.3 0H3A1.5 1.5 0 011.5 13V6zm12 2.5V11h3l-1.9-2.5h-1.1z',
    },
];

const steps = [
    {
        step: '01',
        name: 'Onboard your vendors',
        body: 'Review applications, set each store’s commission rate, and their products go live under your catalog.',
    },
    {
        step: '02',
        name: 'Sell and fulfil',
        body: 'Orders land with the vendor split already calculated. Fulfil in full or in part, attach tracking, and the timeline keeps the record.',
    },
    {
        step: '03',
        name: 'Settle up',
        body: 'Cancellations and refunds adjust the balance automatically. Generate the payout for the period and mark it paid.',
    },
];

const highlights = [
    { value: 'Per line item', label: 'Commission split' },
    { value: 'Partial', label: 'Fulfilment & refunds' },
    { value: 'Multi-rate', label: 'Tax & shipping zones' },
    { value: 'Full audit', label: 'Order timeline' },
];

const statusRows = [
    { label: 'Completed', width: 'w-full', count: '101' },
    { label: 'Refunded', width: 'w-1/5', count: '19' },
    { label: 'Cancelled', width: 'w-1/6', count: '16' },
    { label: 'Shipped', width: 'w-1/6', count: '16' },
    { label: 'Processing', width: 'w-[6%]', count: '4' },
];
</script>

<template>
    <Head title="Run your multi-vendor store">
        <meta
            name="description"
            content="One admin panel for a multi-vendor marketplace: catalog, orders, vendors, cancellations, refunds, payouts, tax and shipping."
        />
    </Head>

    <div
        class="min-h-screen bg-white text-[#303030] dark:bg-[#141414] dark:text-[#e3e3e3]"
    >
        <!-- Nav -->
        <header
            class="sticky top-0 z-50 border-b border-[#e3e3e3] bg-white/85 backdrop-blur dark:border-[#3a3a3a] dark:bg-[#141414]/85"
        >
            <nav
                class="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4 sm:px-6"
            >
                <Link href="/" class="flex items-center gap-2">
                    <span
                        class="flex size-7 items-center justify-center rounded-lg bg-[#00a47c] text-sm font-bold text-white"
                        >M</span
                    >
                    <span class="text-sm font-semibold">Marketplace</span>
                </Link>

                <div
                    class="mx-auto hidden items-center gap-6 text-[13px] text-[#616161] md:flex dark:text-[#b5b5b5]"
                >
                    <a
                        href="#modules"
                        class="hover:text-[#303030] dark:hover:text-[#e3e3e3]"
                        >Modules</a
                    >
                    <a
                        href="#workflow"
                        class="hover:text-[#303030] dark:hover:text-[#e3e3e3]"
                        >How it works</a
                    >
                </div>

                <div class="ml-auto flex items-center gap-2 md:ml-0">
                    <Link
                        v-if="!user"
                        :href="login()"
                        class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#232323]"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="user ? dashboard() : login()"
                        class="rounded-lg bg-[#1a1a1a] px-3 py-1.5 text-[13px] font-semibold text-white hover:bg-[#303030] dark:bg-white dark:text-[#1a1a1a] dark:hover:bg-[#e3e3e3]"
                    >
                        Open dashboard
                    </Link>
                </div>
            </nav>
        </header>

        <main>
            <!-- Hero -->
            <section
                class="mx-auto max-w-6xl px-4 pt-16 pb-12 sm:px-6 sm:pt-24"
            >
                <div class="mx-auto max-w-3xl text-center">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-[#00a47c]/10 px-3 py-1 text-xs font-semibold text-[#00795c] dark:bg-[#00a47c]/15 dark:text-[#4fd1ab]"
                    >
                        <span class="size-1.5 rounded-full bg-[#00a47c]" />
                        Multi-vendor commerce admin
                    </span>

                    <h1
                        class="mt-5 text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        Run your marketplace,<br class="hidden sm:block" />
                        not your spreadsheets.
                    </h1>

                    <p
                        class="mx-auto mt-5 max-w-2xl text-base leading-relaxed text-pretty text-[#616161] sm:text-lg dark:text-[#b5b5b5]"
                    >
                        Catalog, orders, vendors, refunds and payouts in a
                        single admin panel — with the commission split worked
                        out on every line item, so settlement is never a guess.
                    </p>

                    <div
                        class="mt-8 flex flex-wrap items-center justify-center gap-3"
                    >
                        <Link
                            :href="user ? dashboard() : login()"
                            class="rounded-lg bg-[#1a1a1a] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#303030] dark:bg-white dark:text-[#1a1a1a] dark:hover:bg-[#e3e3e3]"
                        >
                            Open dashboard
                        </Link>
                        <a
                            href="#modules"
                            class="rounded-lg border border-[#e3e3e3] px-5 py-2.5 text-sm font-semibold text-[#303030] hover:bg-[#fafafa] dark:border-[#3a3a3a] dark:text-[#e3e3e3] dark:hover:bg-[#232323]"
                        >
                            See what’s inside
                        </a>
                    </div>
                </div>

                <!-- Dashboard preview -->
                <div class="mt-14 sm:mt-16">
                    <div
                        class="overflow-hidden rounded-2xl border border-[#e3e3e3] bg-[#fafafa] shadow-sm dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                    >
                        <div
                            class="flex h-9 items-center gap-2 bg-[#1a1a1a] px-3.5"
                        >
                            <span class="size-2.5 rounded-full bg-[#ff5f57]" />
                            <span class="size-2.5 rounded-full bg-[#febc2e]" />
                            <span class="size-2.5 rounded-full bg-[#28c840]" />
                            <span class="ml-3 text-xs text-[#8a8a8a]"
                                >Dashboard · last 30 days</span
                            >
                        </div>

                        <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                                <div
                                    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                                >
                                    <div
                                        class="rounded-xl border border-[#e3e3e3] bg-white p-3 dark:border-[#3a3a3a] dark:bg-[#232323]"
                                    >
                                        <p class="text-xs text-[#8a8a8a]">
                                            Total sales
                                        </p>
                                        <p class="mt-1 text-xl font-semibold">
                                            ₹7.04L
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-[#e3e3e3] bg-white p-3 dark:border-[#3a3a3a] dark:bg-[#232323]"
                                    >
                                        <p class="text-xs text-[#8a8a8a]">
                                            Orders
                                        </p>
                                        <p class="mt-1 text-xl font-semibold">
                                            160
                                        </p>
                                    </div>
                                    <div
                                        class="col-span-2 rounded-xl border border-[#e3e3e3] bg-white p-3 sm:col-span-1 dark:border-[#3a3a3a] dark:bg-[#232323]"
                                    >
                                        <p class="text-xs text-[#8a8a8a]">
                                            Commission
                                        </p>
                                        <p class="mt-1 text-xl font-semibold">
                                            ₹73.0k
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="mt-3 rounded-xl border border-[#e3e3e3] bg-white p-4 dark:border-[#3a3a3a] dark:bg-[#232323]"
                                >
                                    <p class="text-[13px] font-semibold">
                                        Sales over time
                                    </p>
                                    <svg
                                        viewBox="0 0 320 90"
                                        class="mt-3 h-24 w-full"
                                        preserveAspectRatio="none"
                                        aria-hidden="true"
                                    >
                                        <defs>
                                            <linearGradient
                                                id="spark"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="0%"
                                                    stop-color="#00a47c"
                                                    stop-opacity="0.28"
                                                />
                                                <stop
                                                    offset="100%"
                                                    stop-color="#00a47c"
                                                    stop-opacity="0"
                                                />
                                            </linearGradient>
                                        </defs>
                                        <path
                                            d="M0 70 L27 34 L53 66 L80 46 L107 40 L133 74 L160 24 L187 58 L213 50 L240 62 L267 18 L293 56 L320 44"
                                            fill="none"
                                            stroke="#00a47c"
                                            stroke-width="2"
                                            stroke-linejoin="round"
                                            stroke-linecap="round"
                                        />
                                        <path
                                            d="M0 70 L27 34 L53 66 L80 46 L107 40 L133 74 L160 24 L187 58 L213 50 L240 62 L267 18 L293 56 L320 44 L320 90 L0 90 Z"
                                            fill="url(#spark)"
                                        />
                                    </svg>
                                </div>
                            </div>

                            <div
                                class="rounded-xl border border-[#e3e3e3] bg-white p-4 dark:border-[#3a3a3a] dark:bg-[#232323]"
                            >
                                <p class="text-[13px] font-semibold">
                                    Orders by status
                                </p>
                                <ul class="mt-3 space-y-2.5">
                                    <li
                                        v-for="row in statusRows"
                                        :key="row.label"
                                        class="flex items-center gap-3 text-[13px]"
                                    >
                                        <span
                                            class="w-20 shrink-0 text-[#616161] dark:text-[#b5b5b5]"
                                            >{{ row.label }}</span
                                        >
                                        <span
                                            class="h-1.5 flex-1 rounded-full bg-[#f1f1f1] dark:bg-[#3a3a3a]"
                                        >
                                            <span
                                                class="block h-1.5 rounded-full bg-[#2c6ecb]"
                                                :class="row.width"
                                            />
                                        </span>
                                        <span
                                            class="w-7 shrink-0 text-right font-medium tabular-nums"
                                            >{{ row.count }}</span
                                        >
                                    </li>
                                </ul>

                                <div
                                    class="mt-4 space-y-2 border-t border-[#e3e3e3] pt-4 dark:border-[#3a3a3a]"
                                >
                                    <div
                                        class="flex items-center justify-between text-[13px]"
                                    >
                                        <span
                                            class="text-[#616161] dark:text-[#b5b5b5]"
                                            >Refunds to review</span
                                        >
                                        <span
                                            class="rounded-full bg-[#fdd0d0] px-2 py-0.5 text-xs font-semibold text-[#8e1f0b]"
                                            >13</span
                                        >
                                    </div>
                                    <div
                                        class="flex items-center justify-between text-[13px]"
                                    >
                                        <span
                                            class="text-[#616161] dark:text-[#b5b5b5]"
                                            >Vendors awaiting approval</span
                                        >
                                        <span
                                            class="rounded-full bg-[#ffd79d] px-2 py-0.5 text-xs font-semibold text-[#7e5700]"
                                            >2</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <dl
                    class="mt-10 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-[#e3e3e3] bg-[#e3e3e3] sm:grid-cols-4 dark:border-[#3a3a3a] dark:bg-[#3a3a3a]"
                >
                    <div
                        v-for="item in highlights"
                        :key="item.label"
                        class="bg-white px-4 py-4 text-center dark:bg-[#1a1a1a]"
                    >
                        <dt class="text-base font-semibold">
                            {{ item.value }}
                        </dt>
                        <dd class="mt-0.5 text-xs text-[#8a8a8a]">
                            {{ item.label }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Modules -->
            <section
                id="modules"
                class="scroll-mt-16 border-t border-[#e3e3e3] bg-[#fafafa] py-16 sm:py-24 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                        >
                            Every part of the back office, already built.
                        </h2>
                        <p
                            class="mt-4 text-base text-pretty text-[#616161] dark:text-[#b5b5b5]"
                        >
                            Not a dashboard bolted onto a store — each module
                            knows about the others, so a refund moves stock, the
                            order status and the vendor’s balance together.
                        </p>
                    </div>

                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="item in modules"
                            :key="item.name"
                            class="rounded-xl border border-[#e3e3e3] bg-white p-5 transition hover:shadow-sm dark:border-[#3a3a3a] dark:bg-[#232323]"
                        >
                            <span
                                class="flex size-9 items-center justify-center rounded-lg bg-[#00a47c]/10 text-[#00795c] dark:bg-[#00a47c]/15 dark:text-[#4fd1ab]"
                            >
                                <svg
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    class="size-5"
                                    aria-hidden="true"
                                >
                                    <path :d="item.path" />
                                </svg>
                            </span>
                            <h3 class="mt-3.5 text-sm font-semibold">
                                {{ item.name }}
                            </h3>
                            <p
                                class="mt-1.5 text-[13px] leading-relaxed text-[#616161] dark:text-[#b5b5b5]"
                            >
                                {{ item.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Workflow -->
            <section id="workflow" class="scroll-mt-16 py-16 sm:py-24">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                        >
                            From application to payout.
                        </h2>
                        <p
                            class="mt-4 text-base text-pretty text-[#616161] dark:text-[#b5b5b5]"
                        >
                            The three loops a marketplace actually runs on — and
                            the panel keeps the numbers straight across all of
                            them.
                        </p>
                    </div>

                    <ol class="mt-10 grid gap-6 md:grid-cols-3">
                        <li
                            v-for="item in steps"
                            :key="item.step"
                            class="border-t-2 border-[#00a47c] pt-5"
                        >
                            <span
                                class="text-xs font-semibold tracking-widest text-[#00795c] dark:text-[#4fd1ab]"
                                >{{ item.step }}</span
                            >
                            <h3 class="mt-2 text-base font-semibold">
                                {{ item.name }}
                            </h3>
                            <p
                                class="mt-2 text-[13px] leading-relaxed text-[#616161] dark:text-[#b5b5b5]"
                            >
                                {{ item.body }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Closing CTA -->
            <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
                <div
                    class="rounded-2xl bg-[#1a1a1a] px-6 py-12 text-center sm:px-12 sm:py-16"
                >
                    <h2
                        class="text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl"
                    >
                        Your storefront sells. This runs the rest.
                    </h2>
                    <p
                        class="mx-auto mt-4 max-w-xl text-base text-pretty text-[#b5b5b5]"
                    >
                        Sign in to the admin panel and pick up where the orders
                        are.
                    </p>
                    <Link
                        :href="user ? dashboard() : login()"
                        class="mt-8 inline-block rounded-lg bg-[#00a47c] px-6 py-3 text-sm font-semibold text-white hover:bg-[#00795c]"
                    >
                        {{ user ? 'Open dashboard' : 'Log in to the panel' }}
                    </Link>
                </div>
            </section>
        </main>

        <footer class="border-t border-[#e3e3e3] py-8 dark:border-[#3a3a3a]">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 sm:flex-row sm:px-6"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-6 items-center justify-center rounded-md bg-[#00a47c] text-xs font-bold text-white"
                        >M</span
                    >
                    <span class="text-[13px] font-semibold"
                        >Marketplace Admin</span
                    >
                </div>
                <p class="text-xs text-[#8a8a8a]">
                    Built with Laravel, Inertia and Vue.
                </p>
            </div>
        </footer>
    </div>
</template>
