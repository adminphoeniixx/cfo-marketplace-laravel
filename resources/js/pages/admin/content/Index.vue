<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';

type Banner = {
    id: number;
    title: string;
    subtitle: string | null;
    image_path: string | null;
    deeplink_route: string | null;
    deeplink_params: Record<string, unknown>;
    position: number;
    is_active: boolean;
    is_live: boolean;
    starts_at: string | null;
    ends_at: string | null;
};

type Faq = {
    id: number;
    question: string;
    answer: string;
    topic: string;
    position: number;
    is_active: boolean;
};

type LegalPage = {
    id: number;
    slug: string;
    title: string;
    body: string | null;
    is_published: boolean;
    is_readable: boolean;
    updated_at: string | null;
};

defineProps<{
    banners: Banner[];
    faqs: Faq[];
    pages: LegalPage[];
}>();

const bannerOpen = ref(false);
const editingBanner = ref<Banner | null>(null);

const bannerForm = useForm({
    title: '',
    subtitle: '',
    image_path: '',
    deeplink_route: '',
    position: 0,
    is_active: true,
    starts_at: '',
    ends_at: '',
});

const openBanner = (banner: Banner | null) => {
    editingBanner.value = banner;
    bannerForm.clearErrors();

    Object.assign(bannerForm, {
        title: banner?.title ?? '',
        subtitle: banner?.subtitle ?? '',
        image_path: banner?.image_path ?? '',
        deeplink_route: banner?.deeplink_route ?? '',
        position: banner?.position ?? 0,
        is_active: banner?.is_active ?? true,
        starts_at: banner?.starts_at ?? '',
        ends_at: banner?.ends_at ?? '',
    });

    bannerOpen.value = true;
};

const submitBanner = () => {
    const onSuccess = () => (bannerOpen.value = false);
    const options = { preserveScroll: true, onSuccess };

    if (editingBanner.value) {
        bannerForm.put(`/admin/content/banners/${editingBanner.value.id}`, options);

        return;
    }

    bannerForm.post('/admin/content/banners', options);
};

const toggleBanner = (banner: Banner) =>
    router.patch(
        `/admin/content/banners/${banner.id}/toggle`,
        {},
        { preserveScroll: true },
    );

const destroyBanner = (banner: Banner) =>
    router.delete(`/admin/content/banners/${banner.id}`, { preserveScroll: true });

const faqOpen = ref(false);
const editingFaq = ref<Faq | null>(null);

const faqForm = useForm({
    question: '',
    answer: '',
    topic: 'general',
    position: 0,
    is_active: true,
});

const openFaq = (faq: Faq | null) => {
    editingFaq.value = faq;
    faqForm.clearErrors();

    Object.assign(faqForm, {
        question: faq?.question ?? '',
        answer: faq?.answer ?? '',
        topic: faq?.topic ?? 'general',
        position: faq?.position ?? 0,
        is_active: faq?.is_active ?? true,
    });

    faqOpen.value = true;
};

const submitFaq = () => {
    const onSuccess = () => (faqOpen.value = false);
    const options = { preserveScroll: true, onSuccess };

    if (editingFaq.value) {
        faqForm.put(`/admin/content/faqs/${editingFaq.value.id}`, options);

        return;
    }

    faqForm.post('/admin/content/faqs', options);
};

const destroyFaq = (faq: Faq) =>
    router.delete(`/admin/content/faqs/${faq.id}`, { preserveScroll: true });

const pageOpen = ref(false);
const editingPage = ref<LegalPage | null>(null);

const pageForm = useForm({
    title: '',
    body: '',
    is_published: false,
});

const openPage = (page: LegalPage) => {
    editingPage.value = page;
    pageForm.clearErrors();

    Object.assign(pageForm, {
        title: page.title,
        body: page.body ?? '',
        is_published: page.is_published,
    });

    pageOpen.value = true;
};

const submitPage = () => {
    if (!editingPage.value) {
        return;
    }

    pageForm.put(`/admin/content/pages/${editingPage.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (pageOpen.value = false),
    });
};

const topics = [
    { label: 'General', value: 'general' },
    { label: 'Orders', value: 'orders' },
    { label: 'Returns', value: 'returns' },
    { label: 'Payments', value: 'payments' },
    { label: 'Account', value: 'account' },
];
</script>

<template>
    <Head title="App content" />

    <PageHeader
        title="App content"
        subtitle="The banners, answers and policies the shopper app draws — changed here rather than in a release"
    />

    <PCard
        title="Home banners"
        subtitle="Shown at the top of the app. A banner with dates takes itself down."
        :padding="false"
        class="mb-4"
    >
        <template #actions>
            <PButton variant="primary" size="slim" @click="openBanner(null)">
                Add banner
            </PButton>
        </template>

        <PTable
            v-if="banners.length"
            :headers="['Banner', 'Opens', 'Showing', 'Order', '']"
            :align-right="[2, 3, 4]"
        >
            <tr
                v-for="banner in banners"
                :key="banner.id"
                class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
            >
                <td class="px-4 py-2.5">
                    <p class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]">
                        {{ banner.title }}
                    </p>
                    <p v-if="banner.subtitle" class="text-xs text-[#8a8a8a]">
                        {{ banner.subtitle }}
                    </p>
                    <p v-if="banner.starts_at || banner.ends_at" class="text-xs text-[#8a8a8a]">
                        {{ banner.starts_at ?? 'now' }} → {{ banner.ends_at ?? 'no end' }}
                    </p>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a]">
                    {{ banner.deeplink_route ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="banner.is_live ? 'success' : 'neutral'">
                        {{ banner.is_live ? 'Live' : banner.is_active ? 'Scheduled' : 'Hidden' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a] tabular-nums">
                    {{ banner.position }}
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex justify-end gap-1.5">
                        <PButton size="slim" @click="openBanner(banner)">Edit</PButton>
                        <PButton size="slim" @click="toggleBanner(banner)">
                            {{ banner.is_active ? 'Hide' : 'Show' }}
                        </PButton>
                        <PButton size="slim" variant="critical" @click="destroyBanner(banner)">
                            Delete
                        </PButton>
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No banners"
            description="The app shows none until you add one — which is still better than it carrying its own."
        />
    </PCard>

    <PCard
        title="Help answers"
        subtitle="Shown on the app's help screen, above the phone number"
        :padding="false"
        class="mb-4"
    >
        <template #actions>
            <PButton variant="primary" size="slim" @click="openFaq(null)">
                Add question
            </PButton>
        </template>

        <PTable
            v-if="faqs.length"
            :headers="['Question', 'Topic', 'Status', 'Order', '']"
            :align-right="[1, 2, 3]"
        >
            <tr
                v-for="faq in faqs"
                :key="faq.id"
                class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
            >
                <td class="px-4 py-2.5">
                    <p class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]">
                        {{ faq.question }}
                    </p>
                    <p class="line-clamp-1 text-xs text-[#8a8a8a]">{{ faq.answer }}</p>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a] capitalize">
                    {{ faq.topic }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="faq.is_active ? 'success' : 'neutral'">
                        {{ faq.is_active ? 'Shown' : 'Hidden' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a] tabular-nums">
                    {{ faq.position }}
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex justify-end gap-1.5">
                        <PButton size="slim" @click="openFaq(faq)">Edit</PButton>
                        <PButton size="slim" variant="critical" @click="destroyFaq(faq)">
                            Delete
                        </PButton>
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No answers yet"
            description="Every question here is a call the support line does not get."
        />
    </PCard>

    <PCard
        title="Legal pages"
        subtitle="The five the app links to. A page with nothing on it stays invisible, published or not."
        :padding="false"
    >
        <PTable :headers="['Page', 'Status', 'Last changed', '']" :align-right="[1, 2]">
            <tr
                v-for="page in pages"
                :key="page.id"
                class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
            >
                <td class="px-4 py-2.5">
                    <p class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]">
                        {{ page.title }}
                    </p>
                    <p class="text-xs text-[#8a8a8a]">/{{ page.slug }}</p>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="page.is_readable ? 'success' : 'neutral'">
                        {{ page.is_readable ? 'Live' : page.is_published ? 'Empty' : 'Draft' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a]">
                    {{ page.updated_at ? new Date(page.updated_at).toLocaleDateString() : '—' }}
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex justify-end">
                        <PButton size="slim" @click="openPage(page)">Edit</PButton>
                    </div>
                </td>
            </tr>
        </PTable>
    </PCard>

    <PModal
        :open="bannerOpen"
        :title="editingBanner ? 'Edit banner' : 'Add banner'"
        size="small"
        @close="bannerOpen = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="bannerForm.title"
                label="Title"
                required
                placeholder="Festive edit"
                :error="bannerForm.errors.title"
            />
            <PTextField
                v-model="bannerForm.subtitle"
                label="Subtitle"
                placeholder="Up to 40% off handloom"
                :error="bannerForm.errors.subtitle"
            />
            <PTextField
                v-model="bannerForm.image_path"
                label="Image path"
                placeholder="cfo/banners/festive.jpg"
                :error="bannerForm.errors.image_path"
                help-text="The path in the storage zone, not a URL — the app is handed a signed one."
            />
            <PTextField
                v-model="bannerForm.deeplink_route"
                label="Opens"
                placeholder="category"
                :error="bannerForm.errors.deeplink_route"
                help-text="The app's own route name. Leave blank for a banner that does nothing when tapped."
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <PTextField
                    v-model="bannerForm.starts_at"
                    label="Starts"
                    type="date"
                    :error="bannerForm.errors.starts_at"
                />
                <PTextField
                    v-model="bannerForm.ends_at"
                    label="Ends"
                    type="date"
                    :error="bannerForm.errors.ends_at"
                    help-text="Leave blank to run until you hide it."
                />
            </div>
            <PTextField
                v-model="bannerForm.position"
                label="Sort order"
                type="number"
                min="0"
                :error="bannerForm.errors.position"
                help-text="Lower numbers appear first."
            />
            <PCheckbox v-model="bannerForm.is_active" label="Switched on" />
        </div>

        <template #footer>
            <PButton @click="bannerOpen = false">Cancel</PButton>
            <PButton variant="primary" :loading="bannerForm.processing" @click="submitBanner">
                {{ editingBanner ? 'Save' : 'Add banner' }}
            </PButton>
        </template>
    </PModal>

    <PModal
        :open="faqOpen"
        :title="editingFaq ? 'Edit question' : 'Add question'"
        size="small"
        @close="faqOpen = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="faqForm.question"
                label="Question"
                required
                placeholder="When will my order arrive?"
                :error="faqForm.errors.question"
            />
            <PTextarea
                v-model="faqForm.answer"
                label="Answer"
                required
                :rows="4"
                :error="faqForm.errors.answer"
            />
            <PSelect v-model="faqForm.topic" label="Topic" :options="topics" :error="faqForm.errors.topic" />
            <PTextField
                v-model="faqForm.position"
                label="Sort order"
                type="number"
                min="0"
                :error="faqForm.errors.position"
            />
            <PCheckbox v-model="faqForm.is_active" label="Shown in the app" />
        </div>

        <template #footer>
            <PButton @click="faqOpen = false">Cancel</PButton>
            <PButton variant="primary" :loading="faqForm.processing" @click="submitFaq">
                {{ editingFaq ? 'Save' : 'Add question' }}
            </PButton>
        </template>
    </PModal>

    <PModal
        :open="pageOpen"
        :title="editingPage ? editingPage.title : 'Edit page'"
        size="large"
        @close="pageOpen = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="pageForm.title"
                label="Title"
                required
                :error="pageForm.errors.title"
            />
            <PTextarea
                v-model="pageForm.body"
                label="Body"
                :rows="16"
                :error="pageForm.errors.body"
                help-text="Plain text or HTML. The app shows whatever is here, and the date it last changed."
            />
            <PCheckbox
                v-model="pageForm.is_published"
                label="Published"
                help-text="An empty page stays invisible either way."
            />
        </div>

        <template #footer>
            <PButton @click="pageOpen = false">Cancel</PButton>
            <PButton variant="primary" :loading="pageForm.processing" @click="submitPage">
                Save
            </PButton>
        </template>
    </PModal>
</template>
