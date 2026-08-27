<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PCombobox from '@/components/admin/PCombobox.vue';
import PModal from '@/components/admin/PModal.vue';
import PRichText from '@/components/admin/PRichText.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import PToggle from '@/components/admin/PToggle.vue';
import { compressImage } from '@/lib/compressImage';
import { currency, number, statusTone, titleCase } from '@/lib/format';

type AttributeValue = { id: number; value: string; color_hex: string | null };
type AttributeOption = {
    id: number;
    name: string;
    type: string;
    is_variant: boolean;
    values: AttributeValue[];
};
type VariantValue = { attribute_id: number; attribute_value_id: number };
type Variant = {
    id: number | null;
    name: string;
    sku: string;
    price: number | string;
    compare_at_price: number | string | null;
    stock_quantity: number;
    weight: number;
    is_active: boolean;
    values: VariantValue[];
};
type ImageRow = {
    id: number | null;
    path: string;
    url: string | null;
    alt: string;
};

type Product = {
    id: number;
    name: string;
    slug: string;
    sku: string | null;
    barcode: string | null;
    type: string;
    category_id: number | null;
    tax_class_id: number | null;
    short_description: string | null;
    description: string | null;
    price: string;
    compare_at_price: string | null;
    cost_price: string | null;
    track_inventory: boolean;
    stock_quantity: number;
    low_stock_threshold: number;
    allow_backorder: boolean;
    weight: string;
    length: string | null;
    width: string | null;
    height: string | null;
    requires_shipping: boolean;
    status: string;
    brand: string | null;
    tags: string[] | null;
    seo_title: string | null;
    seo_description: string | null;
    images: {
        id: number;
        path: string;
        url: string | null;
        alt: string | null;
    }[];
    variants: Variant[];
    attributes: { id: number }[];
    categories: { id: number }[];
};

const props = defineProps<{
    product: Product | null;
    stats?: { units_sold: number; revenue: number; orders: number };
    categories: { id: number; name: string; parent_id: number | null }[];
    taxClasses: { id: number; name: string }[];
    attributes: AttributeOption[];
    statuses: string[];
}>();

const isEdit = computed(() => !!props.product);

const form = useForm<{
    name: string;
    slug: string;
    sku: string;
    barcode: string;
    type: string;
    category_id: number | null;
    tax_class_id: number | null;
    short_description: string;
    description: string;
    price: number | string;
    compare_at_price: number | string | null;
    cost_price: number | string | null;
    track_inventory: boolean;
    stock_quantity: number;
    low_stock_threshold: number;
    allow_backorder: boolean;
    weight: number | string;
    length: number | string | null;
    width: number | string | null;
    height: number | string | null;
    requires_shipping: boolean;
    status: string;
    brand: string;
    tags: string[];
    seo_title: string;
    seo_description: string;
    category_ids: number[];
    images: ImageRow[];
    attribute_ids: number[];
    variants: Variant[];
}>({
    name: props.product?.name ?? '',
    slug: props.product?.slug ?? '',
    sku: props.product?.sku ?? '',
    barcode: props.product?.barcode ?? '',
    type: props.product?.type ?? 'simple',
    category_id: props.product?.category_id ?? null,
    tax_class_id: props.product?.tax_class_id ?? null,
    short_description: props.product?.short_description ?? '',
    description: props.product?.description ?? '',
    price: props.product?.price ?? '',
    compare_at_price: props.product?.compare_at_price ?? null,
    cost_price: props.product?.cost_price ?? null,
    track_inventory: props.product?.track_inventory ?? true,
    stock_quantity: props.product?.stock_quantity ?? 0,
    low_stock_threshold: props.product?.low_stock_threshold ?? 5,
    allow_backorder: props.product?.allow_backorder ?? false,
    weight: props.product?.weight ?? 0,
    length: props.product?.length ?? null,
    width: props.product?.width ?? null,
    height: props.product?.height ?? null,
    requires_shipping: props.product?.requires_shipping ?? true,
    status: props.product?.status ?? 'draft',
    brand: props.product?.brand ?? '',
    tags: props.product?.tags ?? [],
    seo_title: props.product?.seo_title ?? '',
    seo_description: props.product?.seo_description ?? '',
    category_ids: props.product?.categories?.map((c) => c.id) ?? [],
    images:
        props.product?.images?.map((image) => ({
            id: image.id,
            path: image.path,
            url: image.url ?? image.path,
            alt: image.alt ?? '',
        })) ?? [],
    attribute_ids: props.product?.attributes?.map((a) => a.id) ?? [],
    variants:
        props.product?.variants?.map((variant) => ({
            id: variant.id,
            name: variant.name ?? '',
            sku: variant.sku ?? '',
            price: variant.price,
            compare_at_price: variant.compare_at_price,
            stock_quantity: variant.stock_quantity,
            weight: Number(variant.weight ?? 0),
            is_active: variant.is_active,
            values: variant.values.map((v) => ({
                attribute_id: v.attribute_id,
                attribute_value_id: v.attribute_value_id,
            })),
        })) ?? [],
});

/* ---------------------------------------------------------- disclosures */
// Shopify keeps the rarely-touched fields behind a chip row you click to open.
const open = ref({
    // Open on arrival when the product already has one of the fields inside,
    // so nothing a seller has set is hidden behind a click.
    pricing: !!props.product?.compare_at_price || !!props.product?.cost_price,
    inventory: false,
    shipping: false,
    seo: false,
});

/* --------------------------------------------------------------- images */
const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref('');
const dragging = ref(false);

const sendFiles = async (files: File[]) => {
    if (!files.length) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    try {
        for (const file of files.slice(0, 10 - form.images.length)) {
            const body = new FormData();
            body.append('file', await compressImage(file));
            body.append('folder', 'products');

            const response = await fetch('/seller/uploads', {
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

            form.images.push({ id: null, path, url, alt: form.name });
        }
    } catch (error) {
        uploadError.value =
            error instanceof Error ? error.message : 'Upload failed.';
    } finally {
        uploading.value = false;
    }
};

const onFilePicked = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    await sendFiles([...(input.files ?? [])]);
    input.value = '';
};

const onDrop = async (event: DragEvent) => {
    dragging.value = false;
    await sendFiles([...(event.dataTransfer?.files ?? [])]);
};

const moveImage = (index: number, direction: -1 | 1) => {
    const target = index + direction;

    if (target < 0 || target >= form.images.length) {
        return;
    }

    const [image] = form.images.splice(index, 1);
    form.images.splice(target, 0, image);
};

/* ----------------------------------------------------------------- tags */
const tagInput = ref('');

const addTag = () => {
    const tag = tagInput.value.trim().replace(/,$/, '');

    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }

    tagInput.value = '';
};

const removeTag = (tag: string) =>
    (form.tags = form.tags.filter((t) => t !== tag));

/* ------------------------------------------------------------- variants */
const variantAttributes = computed(() =>
    props.attributes.filter((attribute) => attribute.is_variant),
);

const valueLabel = (attributeId: number, valueId: number) =>
    props.attributes
        .find((a) => a.id === attributeId)
        ?.values.find((v) => v.id === valueId)?.value ?? '';

const attributeLabel = (attributeId: number) =>
    props.attributes.find((a) => a.id === attributeId)?.name ?? 'Option';

// Which values of each chosen option are in play. Shopify lets you pick the
// three sizes you actually stock rather than every size in the list, so the
// generated table stays the size the seller meant.
const chosenValues = ref<Record<number, number[]>>({});

for (const id of form.attribute_ids) {
    const used = [
        ...new Set(
            form.variants
                .flatMap((variant) => variant.values)
                .filter((value) => value.attribute_id === id)
                .map((value) => value.attribute_value_id),
        ),
    ];

    chosenValues.value[id] =
        used.length > 0
            ? used
            : (props.attributes.find((a) => a.id === id)?.values ?? []).map(
                  (v) => v.id,
              );
}

/** The option currently being edited, or null when the list is at rest. */
const editingOption = ref<number | null>(null);
const optionPickerOpen = ref(false);

const unusedAttributes = computed(() =>
    variantAttributes.value.filter(
        (attribute) => !form.attribute_ids.includes(attribute.id),
    ),
);

const addOption = (attributeId: number) => {
    form.attribute_ids.push(attributeId);
    chosenValues.value[attributeId] = [];
    editingOption.value = attributeId;
    optionPickerOpen.value = false;
};

const removeOption = (attributeId: number) => {
    form.attribute_ids = form.attribute_ids.filter((id) => id !== attributeId);
    delete chosenValues.value[attributeId];

    if (editingOption.value === attributeId) {
        editingOption.value = null;
    }

    regenerate();
};

const toggleValue = (attributeId: number, valueId: number) => {
    const list = chosenValues.value[attributeId] ?? [];

    chosenValues.value[attributeId] = list.includes(valueId)
        ? list.filter((id) => id !== valueId)
        : [...list, valueId];
};

const comboKey = (values: VariantValue[]) =>
    [...values]
        .sort((a, b) => a.attribute_id - b.attribute_id)
        .map((v) => `${v.attribute_id}:${v.attribute_value_id}`)
        .join('|');

/** Cartesian product of the chosen values, preserving rows already edited. */
const regenerate = () => {
    const groups = form.attribute_ids
        .map((id) => ({
            id,
            values: (chosenValues.value[id] ?? []).filter(Boolean),
        }))
        .filter((group) => group.values.length > 0);

    if (!groups.length) {
        form.variants = [];
        form.type = 'simple';

        return;
    }

    let combos: VariantValue[][] = [[]];

    for (const group of groups) {
        const next: VariantValue[][] = [];

        for (const combo of combos) {
            for (const valueId of group.values) {
                next.push([
                    ...combo,
                    { attribute_id: group.id, attribute_value_id: valueId },
                ]);
            }
        }

        combos = next;
    }

    const existing = new Map(
        form.variants.map((variant) => [comboKey(variant.values), variant]),
    );

    form.variants = combos.slice(0, 100).map((values) => {
        const found = existing.get(comboKey(values));

        if (found) {
            return found;
        }

        const label = values
            .map((v) => valueLabel(v.attribute_id, v.attribute_value_id))
            .join(' / ');

        return {
            id: null,
            name: label,
            sku: form.sku
                ? `${form.sku}-${label.replace(/[^a-zA-Z0-9]+/g, '-').toUpperCase()}`
                : '',
            price: form.price,
            compare_at_price: form.compare_at_price,
            stock_quantity: 0,
            weight: Number(form.weight) || 0,
            is_active: true,
            values,
        };
    });

    form.type = 'variable';
};

const doneEditingOption = () => {
    editingOption.value = null;
    regenerate();
};

const variantTitle = (variant: Variant) =>
    variant.values
        .map((v) => valueLabel(v.attribute_id, v.attribute_value_id))
        .filter(Boolean)
        .join(' / ') ||
    variant.name ||
    'Variant';

const removeVariant = (index: number) => form.variants.splice(index, 1);

const hasVariants = computed(() => form.variants.length > 0);

const totalVariantStock = computed(() =>
    form.variants.reduce((sum, v) => sum + Number(v.stock_quantity || 0), 0),
);

// Once variants carry the price and stock, the product-level fields are only
// noise — Shopify hides them the same way.
watch(hasVariants, (has) => {
    if (has) {
        open.value.pricing = false;
    }
});

/* ------------------------------------------------------------- computed */
const margin = computed(() => {
    const price = Number(form.price) || 0;
    const cost = Number(form.cost_price) || 0;

    if (!price || !cost) {
        return null;
    }

    return (((price - cost) / price) * 100).toFixed(1);
});

const profit = computed(() => {
    const price = Number(form.price) || 0;
    const cost = Number(form.cost_price) || 0;

    return price && cost ? price - cost : null;
});

const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: category.id,
        label: category.parent_id ? `— ${category.name}` : category.name,
    })),
);

const taxOptions = computed(() => [
    { value: '', label: 'No tax class' },
    ...props.taxClasses.map((t) => ({ value: t.id, label: t.name })),
]);

const statusOptions = computed(() =>
    props.statuses.map((status) => ({
        value: status,
        label: titleCase(status),
    })),
);

const variantError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`variants.${index}.${field}`];

const submit = () => {
    if (isEdit.value) {
        form.put(`/seller/products/${props.product!.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/seller/products');
    }
};

const discard = () => {
    form.reset();
    form.clearErrors();
};

/* ----------------------------------------------------------- leave guard */
// A product form is long enough that losing it to a stray click on the sidebar
// is a real loss. Shopify stops you; so does this. The browser's own dialog
// covers a closed tab or a refresh, which no app code can intercept; an Inertia
// visit gets the nicer in-page version.
const pending = ref<string | null>(null);
const leaving = ref(false);

const guarded = computed(
    () => form.isDirty && !form.processing && !leaving.value,
);

const onBeforeUnload = (event: BeforeUnloadEvent) => {
    if (guarded.value) {
        event.preventDefault();
    }
};

const stay = () => {
    pending.value = null;
};

const leave = () => {
    leaving.value = true;

    const url = pending.value;
    pending.value = null;

    if (url) {
        router.visit(url);
    }
};

let stopGuard: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);

    stopGuard = router.on('before', (event) => {
        const visit = event.detail.visit;

        // Only ordinary navigations away are worth stopping: the form's own
        // POST/PUT is how the work gets saved, and Inertia's partial reloads
        // are the page talking to itself.
        if (!guarded.value || visit.method !== 'get' || !!visit.only?.length) {
            return;
        }

        pending.value = visit.url.toString();
        event.preventDefault();
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopGuard?.();
});
</script>

<template>
    <Head :title="isEdit ? `Edit ${product!.name}` : 'Add product'" />

    <form @submit.prevent="submit">
        <!-- Contextual save bar: Shopify's, minus the animation. -->
        <div
            v-if="form.isDirty || !isEdit"
            class="sticky top-14 z-30 -mx-4 mb-4 flex items-center gap-3 rounded-b-xl bg-[#1a1a1a] px-4 py-2.5 text-white shadow-lg sm:-mx-6 sm:px-6"
        >
            <svg
                viewBox="0 0 20 20"
                fill="currentColor"
                class="size-4 shrink-0"
            >
                <path
                    d="M10 2a8 8 0 100 16 8 8 0 000-16zm0 4a1 1 0 011 1v3a1 1 0 11-2 0V7a1 1 0 011-1zm0 8.5a1.2 1.2 0 110-2.4 1.2 1.2 0 010 2.4z"
                />
            </svg>
            <span class="text-[13px] font-medium">{{
                isEdit ? 'Unsaved changes' : 'Unsaved product'
            }}</span>
            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-lg bg-white/10 px-3 py-1.5 text-[13px] font-medium hover:bg-white/20"
                    @click="discard"
                >
                    Discard
                </button>
                <button
                    type="submit"
                    class="rounded-lg bg-white px-3 py-1.5 text-[13px] font-semibold text-[#1a1a1a] hover:bg-[#e3e3e3] disabled:opacity-60"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </div>

        <!-- Breadcrumb header -->
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <PButton href="/seller/products" size="slim">
                <svg viewBox="0 0 20 20" fill="currentColor" class="size-4">
                    <path
                        d="M12.7 4.3a1 1 0 010 1.4L8.4 10l4.3 4.3a1 1 0 01-1.4 1.4l-5-5a1 1 0 010-1.4l5-5a1 1 0 011.4 0z"
                    />
                </svg>
            </PButton>
            <h1 class="min-w-0 truncate text-lg font-semibold">
                {{ isEdit ? product!.name : 'Add product' }}
            </h1>
            <PBadge v-if="isEdit" :tone="statusTone(form.status)" dot>
                {{ titleCase(form.status) }}
            </PBadge>
        </div>

        <!-- Sales snapshot on edit -->
        <div v-if="isEdit && stats" class="mb-4 grid gap-3 sm:grid-cols-3">
            <div
                v-for="stat in [
                    { label: 'Units sold', value: number(stats.units_sold) },
                    { label: 'Revenue', value: currency(stats.revenue, 0) },
                    { label: 'Orders', value: number(stats.orders) },
                ]"
                :key="stat.label"
                class="rounded-xl border border-[#e3e3e3] bg-white px-4 py-3 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <p class="text-[11px] tracking-wide text-[#8a8a8a] uppercase">
                    {{ stat.label }}
                </p>
                <p class="mt-0.5 text-lg font-semibold">{{ stat.value }}</p>
            </div>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <!-- ================================================ MAIN -->
            <div class="min-w-0 space-y-4">
                <!-- Title + description -->
                <PCard>
                    <div class="space-y-4">
                        <PTextField
                            v-model="form.name"
                            label="Title"
                            placeholder="Short sleeve t-shirt"
                            :error="form.errors.name"
                            required
                        />
                        <PTextField
                            v-model="form.short_description"
                            label="Subtitle"
                            placeholder="One line shoppers see in listings"
                            :error="form.errors.short_description"
                        />
                        <PRichText
                            v-model="form.description"
                            label="Description"
                            placeholder="What is it, what it is made of, how it is used…"
                            :error="form.errors.description"
                        />
                    </div>
                </PCard>

                <!-- Media -->
                <PCard title="Media">
                    <div
                        class="rounded-xl border-2 border-dashed p-6 text-center transition"
                        :class="
                            dragging
                                ? 'border-[#005bd3] bg-[#ebf5ff] dark:bg-[#002d47]'
                                : 'border-[#d0d0d0] dark:border-[#4a4a4a]'
                        "
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*"
                            multiple
                            class="hidden"
                            @change="onFilePicked"
                        />
                        <div class="flex flex-wrap justify-center gap-2">
                            <PButton
                                size="slim"
                                :loading="uploading"
                                :disabled="
                                    uploading || form.images.length >= 10
                                "
                                @click="fileInput?.click()"
                            >
                                Upload new
                            </PButton>
                        </div>
                        <p class="mt-2 text-xs text-[#8a8a8a]">
                            Drag and drop, or browse. JPG, PNG, WEBP or AVIF up
                            to 5 MB. {{ form.images.length }}/10 added.
                        </p>
                        <p
                            v-if="uploadError"
                            class="mt-2 text-xs font-medium text-[#e51c00]"
                        >
                            {{ uploadError }}
                        </p>
                    </div>

                    <ul
                        v-if="form.images.length"
                        class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-5"
                    >
                        <li
                            v-for="(image, index) in form.images"
                            :key="image.path"
                            class="group relative overflow-hidden rounded-lg border border-[#e3e3e3] dark:border-[#3a3a3a]"
                        >
                            <img
                                :src="image.url ?? image.path"
                                :alt="image.alt"
                                class="aspect-square w-full object-cover"
                            />
                            <span
                                v-if="index === 0"
                                class="absolute top-1 left-1 rounded bg-[#1a1a1a]/80 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                            >
                                Cover
                            </span>
                            <div
                                class="absolute inset-x-0 bottom-0 flex justify-between bg-[#1a1a1a]/75 opacity-0 transition group-hover:opacity-100"
                            >
                                <button
                                    type="button"
                                    class="px-2 py-1 text-xs text-white disabled:opacity-30"
                                    :disabled="index === 0"
                                    aria-label="Move left"
                                    @click="moveImage(index, -1)"
                                >
                                    ←
                                </button>
                                <button
                                    type="button"
                                    class="px-2 py-1 text-xs text-white"
                                    aria-label="Remove image"
                                    @click="form.images.splice(index, 1)"
                                >
                                    ✕
                                </button>
                                <button
                                    type="button"
                                    class="px-2 py-1 text-xs text-white disabled:opacity-30"
                                    :disabled="index === form.images.length - 1"
                                    aria-label="Move right"
                                    @click="moveImage(index, 1)"
                                >
                                    →
                                </button>
                            </div>
                        </li>
                    </ul>
                </PCard>

                <!-- Category -->
                <PCard title="Category">
                    <PCombobox
                        v-model="form.category_id"
                        :options="categoryOptions"
                        :error="form.errors.category_id"
                        placeholder="Choose a product category"
                        help-text="Sets the tax rate and where the product shows up in browse."
                    />
                </PCard>

                <!-- Pricing -->
                <PCard title="Pricing" padding>
                    <PTextField
                        v-model="form.price"
                        label="Price"
                        type="number"
                        step="0.01"
                        min="0"
                        prefix="₹"
                        placeholder="0.00"
                        :error="form.errors.price"
                        required
                        class="sm:max-w-xs"
                    />

                    <button
                        type="button"
                        class="mt-3 flex w-full items-center gap-2 rounded-lg border border-[#e3e3e3] px-3 py-2 text-left hover:bg-[#f7f7f7] dark:border-[#3a3a3a] dark:hover:bg-[#232323]"
                        @click="open.pricing = !open.pricing"
                    >
                        <span
                            v-for="chip in [
                                'Compare-at',
                                'Cost per item',
                                'Tax class',
                                'Margin',
                            ]"
                            :key="chip"
                            class="rounded-md bg-[#f0f0f0] px-1.5 py-0.5 text-[11px] text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                        >
                            {{ chip }}
                        </span>
                        <span class="ml-auto text-xs text-[#8a8a8a]">{{
                            open.pricing ? '▲' : '▼'
                        }}</span>
                    </button>

                    <div v-if="open.pricing" class="mt-3 space-y-3">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <PTextField
                                v-model="form.compare_at_price"
                                label="Compare-at price"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="₹"
                                placeholder="0.00"
                                :error="form.errors.compare_at_price"
                                help-text="Shown struck through next to the price."
                            />
                            <PTextField
                                v-model="form.cost_price"
                                label="Cost per item"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="₹"
                                placeholder="0.00"
                                :error="form.errors.cost_price"
                                help-text="Only you see this."
                            />
                            <PSelect
                                v-model="form.tax_class_id"
                                label="Tax class"
                                :options="taxOptions"
                                :error="form.errors.tax_class_id"
                            />
                        </div>
                        <p
                            v-if="margin"
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            Profit
                            <strong
                                class="text-[#303030] dark:text-[#e3e3e3]"
                                >{{ currency(profit) }}</strong
                            >
                            · Margin
                            <strong class="text-[#303030] dark:text-[#e3e3e3]"
                                >{{ margin }}%</strong
                            >
                        </p>
                    </div>
                </PCard>

                <!-- Inventory -->
                <PCard title="Inventory">
                    <template #actions>
                        <PToggle
                            v-model="form.track_inventory"
                            label="Track quantity"
                        />
                    </template>

                    <div
                        v-if="form.track_inventory && !hasVariants"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <PTextField
                            v-model="form.stock_quantity"
                            label="Quantity"
                            type="number"
                            min="0"
                            :error="form.errors.stock_quantity"
                        />
                        <PTextField
                            v-model="form.low_stock_threshold"
                            label="Low stock alert at"
                            type="number"
                            min="0"
                            :error="form.errors.low_stock_threshold"
                        />
                    </div>
                    <p
                        v-else-if="hasVariants"
                        class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                    >
                        Quantity is set per variant below —
                        <strong>{{ number(totalVariantStock) }}</strong> in
                        stock across {{ form.variants.length }} variants.
                    </p>
                    <p v-else class="text-[13px] text-[#8a8a8a]">
                        Stock is not tracked, so this never sells out.
                    </p>

                    <button
                        type="button"
                        class="mt-3 flex w-full items-center gap-2 rounded-lg border border-[#e3e3e3] px-3 py-2 text-left hover:bg-[#f7f7f7] dark:border-[#3a3a3a] dark:hover:bg-[#232323]"
                        @click="open.inventory = !open.inventory"
                    >
                        <span
                            v-for="chip in ['SKU', 'Barcode', 'Backorders']"
                            :key="chip"
                            class="rounded-md bg-[#f0f0f0] px-1.5 py-0.5 text-[11px] text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                        >
                            {{ chip }}
                        </span>
                        <span class="ml-auto text-xs text-[#8a8a8a]">{{
                            open.inventory ? '▲' : '▼'
                        }}</span>
                    </button>

                    <div v-if="open.inventory" class="mt-3 space-y-3">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <PTextField
                                v-model="form.sku"
                                label="SKU (Stock Keeping Unit)"
                                :error="form.errors.sku"
                            />
                            <PTextField
                                v-model="form.barcode"
                                label="Barcode (ISBN, UPC, GTIN)"
                                :error="form.errors.barcode"
                            />
                        </div>
                        <PCheckbox
                            v-model="form.allow_backorder"
                            label="Continue selling when out of stock"
                        />
                    </div>
                </PCard>

                <!-- Shipping -->
                <PCard title="Shipping">
                    <template #actions>
                        <PToggle
                            v-model="form.requires_shipping"
                            label="Physical product"
                        />
                    </template>

                    <div v-if="form.requires_shipping">
                        <PTextField
                            v-model="form.weight"
                            label="Weight"
                            type="number"
                            step="0.001"
                            min="0"
                            suffix="kg"
                            :error="form.errors.weight"
                            class="sm:max-w-xs"
                        />

                        <button
                            type="button"
                            class="mt-3 flex w-full items-center gap-2 rounded-lg border border-[#e3e3e3] px-3 py-2 text-left hover:bg-[#f7f7f7] dark:border-[#3a3a3a] dark:hover:bg-[#232323]"
                            @click="open.shipping = !open.shipping"
                        >
                            <span
                                v-for="chip in ['Length', 'Width', 'Height']"
                                :key="chip"
                                class="rounded-md bg-[#f0f0f0] px-1.5 py-0.5 text-[11px] text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                            >
                                {{ chip }}
                            </span>
                            <span class="ml-auto text-xs text-[#8a8a8a]">{{
                                open.shipping ? '▲' : '▼'
                            }}</span>
                        </button>

                        <div
                            v-if="open.shipping"
                            class="mt-3 grid gap-4 sm:grid-cols-3"
                        >
                            <PTextField
                                v-model="form.length"
                                label="Length"
                                type="number"
                                step="0.1"
                                min="0"
                                suffix="cm"
                            />
                            <PTextField
                                v-model="form.width"
                                label="Width"
                                type="number"
                                step="0.1"
                                min="0"
                                suffix="cm"
                            />
                            <PTextField
                                v-model="form.height"
                                label="Height"
                                type="number"
                                step="0.1"
                                min="0"
                                suffix="cm"
                            />
                        </div>
                    </div>
                    <p v-else class="text-[13px] text-[#8a8a8a]">
                        Digital product — no weight or delivery needed.
                    </p>
                </PCard>

                <!-- Variants -->
                <PCard title="Variants">
                    <p
                        v-if="!variantAttributes.length"
                        class="text-[13px] text-[#8a8a8a]"
                    >
                        The marketplace has not set up any variant options yet.
                    </p>

                    <template v-else>
                        <!-- Chosen options -->
                        <ul
                            v-if="form.attribute_ids.length"
                            class="divide-y divide-[#e3e3e3] rounded-lg border border-[#e3e3e3] dark:divide-[#3a3a3a] dark:border-[#3a3a3a]"
                        >
                            <li
                                v-for="attributeId in form.attribute_ids"
                                :key="attributeId"
                                class="p-3"
                            >
                                <!-- Editing -->
                                <div v-if="editingOption === attributeId">
                                    <p class="text-[13px] font-semibold">
                                        {{ attributeLabel(attributeId) }}
                                    </p>
                                    <p
                                        class="mt-0.5 mb-2 text-xs text-[#8a8a8a]"
                                    >
                                        Pick the ones you stock.
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="value in props.attributes.find(
                                                (a) => a.id === attributeId,
                                            )?.values ?? []"
                                            :key="value.id"
                                            type="button"
                                            class="rounded-lg border px-2.5 py-1 text-[13px] transition"
                                            :class="
                                                (
                                                    chosenValues[attributeId] ??
                                                    []
                                                ).includes(value.id)
                                                    ? 'border-[#303030] bg-[#303030] text-white dark:border-white dark:bg-white dark:text-[#1a1a1a]'
                                                    : 'border-[#d0d0d0] hover:bg-[#f1f1f1] dark:border-[#4a4a4a] dark:hover:bg-[#303030]'
                                            "
                                            @click="
                                                toggleValue(
                                                    attributeId,
                                                    value.id,
                                                )
                                            "
                                        >
                                            <span
                                                v-if="value.color_hex"
                                                class="mr-1.5 inline-block size-2.5 rounded-full align-middle ring-1 ring-black/10"
                                                :style="{
                                                    backgroundColor:
                                                        value.color_hex,
                                                }"
                                            />
                                            {{ value.value }}
                                        </button>
                                    </div>
                                    <div
                                        class="mt-3 flex items-center justify-between"
                                    >
                                        <button
                                            type="button"
                                            class="text-[13px] font-medium text-[#e51c00] hover:underline"
                                            @click="removeOption(attributeId)"
                                        >
                                            Delete
                                        </button>
                                        <PButton
                                            size="slim"
                                            variant="primary"
                                            @click="doneEditingOption"
                                        >
                                            Done
                                        </PButton>
                                    </div>
                                </div>

                                <!-- At rest -->
                                <button
                                    v-else
                                    type="button"
                                    class="w-full rounded-lg px-1 py-0.5 text-left hover:bg-[#f7f7f7] dark:hover:bg-[#232323]"
                                    @click="editingOption = attributeId"
                                >
                                    <span
                                        class="block text-[13px] font-semibold"
                                        >{{ attributeLabel(attributeId) }}</span
                                    >
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-for="valueId in chosenValues[
                                                attributeId
                                            ] ?? []"
                                            :key="valueId"
                                            class="rounded-md bg-[#f0f0f0] px-1.5 py-0.5 text-[11px] text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                                        >
                                            {{
                                                valueLabel(attributeId, valueId)
                                            }}
                                        </span>
                                        <span
                                            v-if="
                                                !(
                                                    chosenValues[attributeId] ??
                                                    []
                                                ).length
                                            "
                                            class="text-[11px] text-[#8a8a8a]"
                                            >No values picked yet</span
                                        >
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <!-- Add option -->
                        <div class="relative mt-2">
                            <button
                                v-if="unusedAttributes.length"
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-1 py-1.5 text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                                @click="optionPickerOpen = !optionPickerOpen"
                            >
                                <svg
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    class="size-4"
                                >
                                    <path
                                        d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
                                    />
                                </svg>
                                Add options like size or colour
                            </button>

                            <div
                                v-if="optionPickerOpen"
                                class="absolute z-10 mt-1 w-64 overflow-hidden rounded-xl border border-[#e3e3e3] bg-white py-1 shadow-xl dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            >
                                <button
                                    v-for="attribute in unusedAttributes"
                                    :key="attribute.id"
                                    type="button"
                                    class="block w-full px-3 py-1.5 text-left text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#303030]"
                                    @click="addOption(attribute.id)"
                                >
                                    {{ attribute.name }}
                                    <span class="text-xs text-[#8a8a8a]"
                                        >·
                                        {{ attribute.values.length }}
                                        values</span
                                    >
                                </button>
                            </div>
                        </div>

                        <!-- Generated variant table -->
                        <div
                            v-if="hasVariants"
                            class="mt-4 overflow-x-auto rounded-lg border border-[#e3e3e3] dark:border-[#3a3a3a]"
                        >
                            <table class="w-full min-w-[560px] text-[13px]">
                                <thead
                                    class="bg-[#f7f7f7] text-left text-[11px] tracking-wide text-[#616161] uppercase dark:bg-[#232323] dark:text-[#b5b5b5]"
                                >
                                    <tr>
                                        <th class="px-3 py-2 font-semibold">
                                            Variant
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Price
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Available
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            SKU
                                        </th>
                                        <th class="w-8" />
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[#e3e3e3] dark:divide-[#3a3a3a]"
                                >
                                    <tr
                                        v-for="(
                                            variant, index
                                        ) in form.variants"
                                        :key="index"
                                    >
                                        <td class="px-3 py-2 font-medium">
                                            {{ variantTitle(variant) }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <PTextField
                                                v-model="variant.price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                prefix="₹"
                                                :error="
                                                    variantError(index, 'price')
                                                "
                                            />
                                        </td>
                                        <td class="px-3 py-2">
                                            <PTextField
                                                v-model="variant.stock_quantity"
                                                type="number"
                                                min="0"
                                                :error="
                                                    variantError(
                                                        index,
                                                        'stock_quantity',
                                                    )
                                                "
                                            />
                                        </td>
                                        <td class="px-3 py-2">
                                            <PTextField
                                                v-model="variant.sku"
                                                :error="
                                                    variantError(index, 'sku')
                                                "
                                            />
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <button
                                                type="button"
                                                class="text-[#8a8a8a] hover:text-[#e51c00]"
                                                aria-label="Remove variant"
                                                @click="removeVariant(index)"
                                            >
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p
                                class="border-t border-[#e3e3e3] px-3 py-2 text-xs text-[#616161] dark:border-[#3a3a3a] dark:text-[#b5b5b5]"
                            >
                                Total inventory:
                                {{ number(totalVariantStock) }} available
                            </p>
                        </div>
                    </template>
                </PCard>

                <!-- Search engine listing -->
                <PCard title="Search engine listing">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-lg border border-[#e3e3e3] px-3 py-2 text-left hover:bg-[#f7f7f7] dark:border-[#3a3a3a] dark:hover:bg-[#232323]"
                        @click="open.seo = !open.seo"
                    >
                        <span
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{
                                form.seo_title ||
                                form.name ||
                                'Add a title and description to see how this product might appear in search'
                            }}
                        </span>
                        <span class="ml-auto text-xs text-[#8a8a8a]">{{
                            open.seo ? '▲' : '▼'
                        }}</span>
                    </button>

                    <div v-if="open.seo" class="mt-3 space-y-3">
                        <PTextField
                            v-model="form.seo_title"
                            label="Page title"
                            :error="form.errors.seo_title"
                            :help-text="`${form.seo_title.length} of 70 characters used`"
                        />
                        <PTextarea
                            v-model="form.seo_description"
                            label="Meta description"
                            :rows="3"
                            :error="form.errors.seo_description"
                        />
                        <PTextField
                            v-model="form.slug"
                            label="URL handle"
                            prefix="/products/"
                            :error="form.errors.slug"
                            help-text="Leave blank to build one from the title."
                        />
                    </div>
                </PCard>
            </div>

            <!-- ============================================= SIDEBAR -->
            <div class="space-y-4 lg:sticky lg:top-20">
                <PCard title="Status">
                    <PSelect
                        v-model="form.status"
                        :options="statusOptions"
                        :error="form.errors.status"
                    />
                    <p class="mt-2 text-xs text-[#8a8a8a]">
                        Only <strong>Active</strong> products are visible to
                        shoppers.
                    </p>
                </PCard>

                <PCard title="Product organisation">
                    <div class="space-y-3">
                        <PTextField
                            v-model="form.brand"
                            label="Brand"
                            :error="form.errors.brand"
                        />

                        <div>
                            <p class="mb-1 text-[13px] font-medium">Tags</p>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="tag in form.tags"
                                    :key="tag"
                                    class="inline-flex items-center gap-1 rounded-md bg-[#f0f0f0] py-0.5 pr-1 pl-2 text-[12px] dark:bg-[#303030]"
                                >
                                    {{ tag }}
                                    <button
                                        type="button"
                                        class="text-[#8a8a8a] hover:text-[#e51c00]"
                                        :aria-label="`Remove ${tag}`"
                                        @click="removeTag(tag)"
                                    >
                                        ✕
                                    </button>
                                </span>
                            </div>
                            <input
                                v-model="tagInput"
                                type="text"
                                placeholder="Add tag and press Enter"
                                class="mt-2 w-full rounded-lg border border-[#d0d0d0] bg-white px-2.5 py-1.5 text-[13px] outline-none focus:border-[#005bd3] focus:ring-1 focus:ring-[#005bd3] dark:border-[#4a4a4a] dark:bg-[#303030]"
                                @keydown.enter.prevent="addTag"
                                @keydown.,.prevent="addTag"
                                @blur="addTag"
                            />
                        </div>

                        <div>
                            <p class="mb-1 text-[13px] font-medium">
                                Also list under
                            </p>
                            <div
                                class="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-[#e3e3e3] p-2 dark:border-[#3a3a3a]"
                            >
                                <label
                                    v-for="category in categories"
                                    :key="category.id"
                                    class="flex items-center gap-2 text-[13px]"
                                >
                                    <input
                                        v-model="form.category_ids"
                                        type="checkbox"
                                        :value="category.id"
                                        class="size-3.5 rounded"
                                    />
                                    <span
                                        :class="
                                            category.parent_id ? 'pl-2' : ''
                                        "
                                        >{{ category.name }}</span
                                    >
                                </label>
                            </div>
                        </div>
                    </div>
                </PCard>

                <PCard title="Store">
                    <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                        This product is filed under your own store. Only the
                        marketplace admin can move it.
                    </p>
                </PCard>

                <div class="flex flex-wrap gap-2">
                    <PButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        {{ isEdit ? 'Save changes' : 'Add product' }}
                    </PButton>
                    <PButton href="/seller/products">Cancel</PButton>
                </div>
            </div>
        </div>

        <PModal
            :open="pending !== null"
            title="Leave page with unsaved changes?"
            size="small"
            @close="stay"
        >
            <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                Leaving this page will delete all unsaved changes.
            </p>
            <template #footer>
                <PButton @click="stay">Stay</PButton>
                <PButton variant="critical" @click="leave">Leave page</PButton>
            </template>
        </PModal>
    </form>
</template>
