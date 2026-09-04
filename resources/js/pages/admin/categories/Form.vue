<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { compressImage } from '@/lib/compressImage';

type Category = {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    description: string | null;
    image_path: string | null;
    image_url: string | null;
    icon: string | null;
    position: number;
    is_active: boolean;
    is_featured: boolean;
    seo_title: string | null;
    seo_description: string | null;
};

const props = defineProps<{
    category: Category | null;
    parents: { id: number; name: string; parent_id: number | null }[];
}>();

const isEdit = computed(() => !!props.category);

const form = useForm({
    name: props.category?.name ?? '',
    slug: props.category?.slug ?? '',
    parent_id: props.category?.parent_id ?? null,
    description: props.category?.description ?? '',
    image_path: props.category?.image_path ?? '',
    icon: props.category?.icon ?? '',
    position: props.category?.position ?? 0,
    is_active: props.category?.is_active ?? true,
    is_featured: props.category?.is_featured ?? false,
    seo_title: props.category?.seo_title ?? '',
    seo_description: props.category?.seo_description ?? '',
});

/* Preview follows whatever the field currently holds: the resolved URL for a
   saved image, the pasted URL, or the URL returned by an upload. */
const preview = ref(
    props.category?.image_url ?? props.category?.image_path ?? '',
);
const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref('');

const uploadImage = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    try {
        const body = new FormData();
        body.append('file', await compressImage(file));
        body.append('folder', 'categories');

        const response = await fetch('/admin/uploads', {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));

            throw new Error(
                payload.message ?? `Upload failed (${response.status})`,
            );
        }

        const { path, url } = await response.json();

        form.image_path = path;
        preview.value = url;
    } catch (error) {
        uploadError.value =
            error instanceof Error ? error.message : 'Upload failed.';
    } finally {
        uploading.value = false;
        input.value = '';
    }
};

const parentOptions = computed(() => [
    { value: '', label: 'None (top level)' },
    ...props.parents.map((parent) => ({
        value: parent.id,
        label: parent.parent_id ? `— ${parent.name}` : parent.name,
    })),
]);

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/categories/${props.category!.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/admin/categories');
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${category!.name}` : 'New category'" />

    <form @submit.prevent="submit">
        <PageHeader
            :title="isEdit ? category!.name : 'Add category'"
            back-href="/admin/categories"
        >
            <template #actions>
                <PButton href="/admin/categories">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Save changes' : 'Create category' }}
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard title="Details">
                    <div class="space-y-3">
                        <PTextField
                            v-model="form.name"
                            label="Name"
                            required
                            :error="form.errors.name"
                            placeholder="e.g. Men's Footwear"
                        />
                        <PTextField
                            v-model="form.slug"
                            label="URL handle"
                            :error="form.errors.slug"
                            prefix="/category/"
                            :placeholder="
                                form.name
                                    ? form.name
                                          .toLowerCase()
                                          .replace(/\s+/g, '-')
                                    : 'auto-generated'
                            "
                            help-text="Leave blank to generate from the name."
                        />
                        <PTextField
                            v-model="form.icon"
                            label="Icon"
                            placeholder="🥻"
                            :error="form.errors.icon"
                            help-text="One emoji, drawn in the shopper app where there is no photograph. Leave blank to pick one from the name."
                        />
                        <PTextarea
                            v-model="form.description"
                            label="Description"
                            :rows="5"
                            :error="form.errors.description"
                        />
                    </div>
                </PCard>

                <PCard
                    title="Search engine listing"
                    subtitle="How this category appears in search results"
                >
                    <div class="space-y-3">
                        <PTextField
                            v-model="form.seo_title"
                            label="Page title"
                            :error="form.errors.seo_title"
                            :help-text="`${(form.seo_title || '').length} of 70 characters used`"
                        />
                        <PTextarea
                            v-model="form.seo_description"
                            label="Meta description"
                            :rows="3"
                            :error="form.errors.seo_description"
                        />
                    </div>

                    <div
                        class="mt-4 rounded-lg border border-[#e3e3e3] bg-[#fafafa] p-3 dark:border-[#3a3a3a] dark:bg-[#232323]"
                    >
                        <p
                            class="truncate text-[15px] text-[#1a0dab] dark:text-[#8ab4f8]"
                        >
                            {{
                                form.seo_title || form.name || 'Category title'
                            }}
                        </p>
                        <p
                            class="truncate text-xs text-[#0c6b1d] dark:text-[#7cb342]"
                        >
                            store.example.com/category/{{
                                form.slug || 'handle'
                            }}
                        </p>
                        <p
                            class="mt-0.5 line-clamp-2 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{
                                form.seo_description ||
                                form.description ||
                                'Add a description to control the snippet shown here.'
                            }}
                        </p>
                    </div>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Organisation">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.parent_id"
                            label="Parent category"
                            :options="parentOptions"
                            :error="form.errors.parent_id"
                        />
                        <PTextField
                            v-model="form.position"
                            label="Sort position"
                            type="number"
                            min="0"
                            :error="form.errors.position"
                            help-text="Lower numbers appear first."
                        />
                    </div>
                </PCard>

                <PCard title="Visibility">
                    <div class="space-y-2.5">
                        <PCheckbox
                            v-model="form.is_active"
                            label="Active"
                            help-text="Visible on the storefront."
                        />
                        <PCheckbox
                            v-model="form.is_featured"
                            label="Featured"
                            help-text="Highlight on the home page."
                        />
                    </div>
                </PCard>

                <PCard title="Image">
                    <PTextField
                        v-model="form.image_path"
                        label="Image URL"
                        placeholder="https://…"
                        :error="form.errors.image_path"
                        @update:model-value="preview = String($event)"
                    />
                    <div class="mt-2 flex items-center gap-2">
                        <PButton
                            size="slim"
                            :loading="uploading"
                            :disabled="uploading"
                            @click="fileInput?.click()"
                        >
                            {{ uploading ? 'Uploading…' : 'Upload image' }}
                        </PButton>
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="uploadImage"
                        />
                        <span v-if="uploadError" class="text-xs text-[#e51c00]">
                            {{ uploadError }}
                        </span>
                    </div>
                    <div
                        v-if="preview"
                        class="mt-3 overflow-hidden rounded-lg border border-[#e3e3e3] dark:border-[#3a3a3a]"
                    >
                        <img
                            :src="preview"
                            :alt="form.name"
                            class="h-32 w-full object-cover"
                        />
                    </div>
                </PCard>
            </div>
        </div>
    </form>
</template>
