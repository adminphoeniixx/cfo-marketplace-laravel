<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    page: {
        slug: string;
        title: string;
        html: string;
        sections: { id: string; title: string }[];
        updated_at: string | null;
    };
    others: { slug: string; title: string }[];
    store: { name: string; email: string | null };
}>();

const longDate = (value: string) =>
    new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
</script>

<template>
    <Head>
        <title>{{ `${page.title} — ${store.name}` }}</title>
        <meta
            name="description"
            :content="`${page.title} for ${store.name}.`"
        />
    </Head>

    <div
        class="min-h-screen bg-[#f7f7f7] text-[#303030] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
    >
        <header
            class="sticky top-0 z-10 border-b border-[#e3e3e3] bg-white/85 backdrop-blur dark:border-[#2a2a2a] dark:bg-[#1a1a1a]/85"
        >
            <div
                class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-5 py-3.5"
            >
                <a
                    href="/"
                    class="text-[13px] font-semibold hover:text-[#00795c] dark:hover:text-[#4fd1ab]"
                >
                    {{ store.name }}
                </a>
                <nav class="flex items-center gap-4 text-xs text-[#8a8a8a]">
                    <a
                        v-for="other in others"
                        :key="other.slug"
                        :href="`/legal/${other.slug}`"
                        class="hidden hover:text-[#303030] hover:underline sm:inline dark:hover:text-[#e3e3e3]"
                        >{{ other.title }}</a
                    >
                    <span
                        class="rounded-full bg-[#f0f0f0] px-2.5 py-1 font-medium text-[#616161] dark:bg-[#2a2a2a] dark:text-[#b5b5b5]"
                        >Legal</span
                    >
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-5 pb-16">
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
                    {{ page.title }}
                </h1>
                <p
                    v-if="page.updated_at"
                    class="mt-4 inline-flex items-center gap-2 rounded-full border border-[#e3e3e3] bg-white px-3 py-1 text-xs text-[#616161] dark:border-[#2a2a2a] dark:bg-[#232323] dark:text-[#b5b5b5]"
                >
                    <span class="size-1.5 rounded-full bg-[#00a47c]" />
                    Last updated {{ longDate(page.updated_at) }}
                </p>
            </div>

            <div
                class="gap-12 py-10 lg:grid lg:grid-cols-[13rem_minmax(0,1fr)]"
            >
                <aside
                    v-if="page.sections.length > 1"
                    class="hidden lg:sticky lg:top-24 lg:block lg:self-start"
                >
                    <p
                        class="text-xs font-semibold tracking-wide text-[#8a8a8a] uppercase"
                    >
                        On this page
                    </p>
                    <ul
                        class="mt-3 space-y-2 border-l border-[#e3e3e3] dark:border-[#2a2a2a]"
                    >
                        <li v-for="section in page.sections" :key="section.id">
                            <a
                                :href="`#${section.id}`"
                                class="-ml-px block border-l border-transparent pl-3 text-[13px] leading-snug text-[#616161] hover:border-[#00a47c] hover:text-[#303030] dark:text-[#b5b5b5] dark:hover:text-[#e3e3e3]"
                                >{{ section.title }}</a
                            >
                        </li>
                    </ul>
                </aside>

                <div class="min-w-0">
                    <!-- Markdown or HTML the admin panel wrote, put through a
                         whitelist server-side — nothing here can run. -->
                    <article
                        class="legal-body rounded-xl border border-[#e3e3e3] bg-white px-6 py-7 sm:px-9 sm:py-10 dark:border-[#2a2a2a] dark:bg-[#232323]"
                        v-html="page.html"
                    />

                    <footer
                        class="mt-8 text-sm text-[#616161] dark:text-[#b5b5b5]"
                    >
                        <p v-if="store.email">
                            Questions about this page?
                            <a
                                class="font-medium text-[#00795c] underline dark:text-[#4fd1ab]"
                                :href="`mailto:${store.email}`"
                                >{{ store.email }}</a
                            >
                        </p>
                        <ul
                            v-if="others.length"
                            class="mt-4 flex flex-wrap gap-2"
                        >
                            <li v-for="other in others" :key="other.slug">
                                <a
                                    :href="`/legal/${other.slug}`"
                                    class="inline-block rounded-full border border-[#e3e3e3] bg-white px-3 py-1.5 text-[13px] hover:border-[#00a47c] hover:text-[#00795c] dark:border-[#2a2a2a] dark:bg-[#232323] dark:hover:border-[#4fd1ab] dark:hover:text-[#4fd1ab]"
                                    >{{ other.title }}</a
                                >
                            </li>
                        </ul>
                    </footer>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
/*
 * The body arrives as HTML, so there is nothing here to hang a class on —
 * these are element rules, kept to this page by `:deep`.
 */
.legal-body :deep(> :first-child) {
    margin-top: 0;
}

.legal-body :deep(h2) {
    /* Clear of the sticky header when jumped to from the contents. */
    scroll-margin-top: 5.5rem;
    margin-top: 2.5rem;
    padding-top: 1.75rem;
    border-top: 1px solid #f0f0f0;
    font-size: 1.1875rem;
    font-weight: 600;
    letter-spacing: -0.01em;
}

.legal-body :deep(h2:first-child) {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}

.legal-body :deep(h3),
.legal-body :deep(h4) {
    scroll-margin-top: 5.5rem;
    margin-top: 1.75rem;
    font-size: 1rem;
    font-weight: 600;
}

.legal-body :deep(p),
.legal-body :deep(li) {
    margin-top: 0.875rem;
    font-size: 0.9375rem;
    line-height: 1.75;
    color: #474747;
}

.legal-body :deep(ul),
.legal-body :deep(ol) {
    margin-top: 0.875rem;
    padding-left: 1.125rem;
    list-style: disc;
}

.legal-body :deep(ol) {
    list-style: decimal;
}

.legal-body :deep(li) {
    margin-top: 0.375rem;
    padding-left: 0.25rem;
}

.legal-body :deep(li::marker) {
    color: #a8a8a8;
}

.legal-body :deep(strong),
.legal-body :deep(b) {
    font-weight: 600;
    color: #303030;
}

.legal-body :deep(a) {
    color: #00795c;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.legal-body :deep(blockquote) {
    margin-top: 1.25rem;
    border-left: 2px solid #00a47c;
    padding-left: 1rem;
}

.legal-body :deep(hr) {
    margin: 2rem 0;
    border-top: 1px solid #e3e3e3;
}

/*
 * The licence list is one long block of package names. It wraps rather than
 * scrolling sideways, and sits at a size that does not shout.
 */
.legal-body :deep(pre) {
    margin-top: 1rem;
    border: 1px solid #f0f0f0;
    border-radius: 0.5rem;
    background: #fafafa;
    padding: 0.875rem 1rem;
    font-size: 0.8125rem;
    line-height: 1.7;
    color: #474747;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.legal-body :deep(code) {
    border-radius: 0.25rem;
    background: #f2f2f2;
    padding: 0.1rem 0.3rem;
    font-size: 0.875em;
}

.legal-body :deep(pre code) {
    background: none;
    padding: 0;
    font-size: inherit;
}

.legal-body :deep(table) {
    margin-top: 1rem;
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.legal-body :deep(th),
.legal-body :deep(td) {
    border: 1px solid #e3e3e3;
    padding: 0.5rem 0.625rem;
    text-align: left;
    vertical-align: top;
}

.legal-body :deep(th) {
    background: #f7f7f7;
    font-weight: 600;
}

.dark .legal-body :deep(p),
.dark .legal-body :deep(li),
.dark .legal-body :deep(pre) {
    color: #b5b5b5;
}

.dark .legal-body :deep(strong),
.dark .legal-body :deep(b) {
    color: #e3e3e3;
}

.dark .legal-body :deep(a) {
    color: #4fd1ab;
}

.dark .legal-body :deep(h2) {
    border-top-color: #2a2a2a;
}

.dark .legal-body :deep(pre),
.dark .legal-body :deep(code) {
    border-color: #2a2a2a;
    background: #1f1f1f;
}

.dark .legal-body :deep(hr),
.dark .legal-body :deep(th),
.dark .legal-body :deep(td) {
    border-color: #2a2a2a;
}

.dark .legal-body :deep(th) {
    background: #1f1f1f;
}
</style>
