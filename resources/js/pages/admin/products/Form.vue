<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import {
    compactCurrency,
    currency,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

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
type ImageRow = { id: number | null; path: string; alt: string };

type Product = {
    id: number;
    name: string;
    slug: string;
    sku: string | null;
    barcode: string | null;
    type: string;
    vendor_id: number | null;
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
    is_featured: boolean;
    brand: string | null;
    tags: string[] | null;
    seo_title: string | null;
    seo_description: string | null;
    images: { id: number; path: string; alt: string | null }[];
    variants: (Variant & {
        values: { attribute_id: number; attribute_value_id: number }[];
    })[];
    attributes: { id: number }[];
    categories: { id: number }[];
};

const props = defineProps<{
    product: Product | null;
    stats?: { units_sold: number; revenue: number; orders: number };
    vendors: { id: number; name: string; commission_rate: string }[];
    categories: { id: number; name: string; parent_id: number | null }[];
    taxClasses: { id: number; name: string }[];
    attributes: AttributeOption[];
    statuses: string[];
}>();

const isEdit = computed(() => !!props.product);
const tab = ref<'general' | 'variants' | 'shipping' | 'seo'>('general');

const form = useForm<{
    name: string;
    slug: string;
    sku: string;
    barcode: string;
    type: string;
    vendor_id: number | null;
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
    is_featured: boolean;
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
    vendor_id: props.product?.vendor_id ?? null,
    category_id: props.product?.category_id ?? null,
    tax_class_id: props.product?.tax_class_id ?? null,
    short_description: props.product?.short_description ?? '',
    description: props.product?.description ?? '',
    price: props.product?.price ?? 0,
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
    is_featured: props.product?.is_featured ?? false,
    brand: props.product?.brand ?? '',
    tags: props.product?.tags ?? [],
    seo_title: props.product?.seo_title ?? '',
    seo_description: props.product?.seo_description ?? '',
    category_ids:
        props.product?.categories?.map((category) => category.id) ?? [],
    images:
        props.product?.images?.map((image) => ({
            id: image.id,
            path: image.path,
            alt: image.alt ?? '',
        })) ?? [],
    attribute_ids:
        props.product?.attributes?.map((attribute) => attribute.id) ?? [],
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
            values: variant.values.map((value) => ({
                attribute_id: value.attribute_id,
                attribute_value_id: value.attribute_value_id,
            })),
        })) ?? [],
});

/* ------------------------------------------------------------------ tags */
const tagInput = ref('');

const addTag = () => {
    const tag = tagInput.value.trim();

    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }

    tagInput.value = '';
};

const removeTag = (tag: string) =>
    (form.tags = form.tags.filter((t) => t !== tag));

/* ---------------------------------------------------------------- images */
const imageInput = ref('');

const addImage = () => {
    const path = imageInput.value.trim();

    if (path) {
        form.images.push({ id: null, path, alt: form.name });
        imageInput.value = '';
    }
};

const moveImage = (index: number, direction: -1 | 1) => {
    const target = index + direction;

    if (target < 0 || target >= form.images.length) {
        return;
    }

    const [image] = form.images.splice(index, 1);
    form.images.splice(target, 0, image);
};

/* -------------------------------------------------------------- variants */
const variantAttributes = computed(() =>
    props.attributes.filter(
        (attribute) =>
            attribute.is_variant && form.attribute_ids.includes(attribute.id),
    ),
);

const valueLabel = (attributeId: number, valueId: number) =>
    props.attributes
        .find((a) => a.id === attributeId)
        ?.values.find((v) => v.id === valueId)?.value ?? '';

const variantTitle = (variant: Variant) =>
    variant.values
        .map((value) =>
            valueLabel(value.attribute_id, value.attribute_value_id),
        )
        .filter(Boolean)
        .join(' / ') ||
    variant.name ||
    'Variant';

const toggleAttribute = (id: number) => {
    const index = form.attribute_ids.indexOf(id);

    if (index === -1) {
        form.attribute_ids.push(id);
    } else {
        form.attribute_ids.splice(index, 1);
    }
};

/** Cartesian product of the selected attributes' values. */
const generateVariants = () => {
    const groups = variantAttributes.value.filter(
        (attribute) => attribute.values.length,
    );

    if (!groups.length) {
        return;
    }

    let combos: VariantValue[][] = [[]];

    for (const attribute of groups) {
        const next: VariantValue[][] = [];

        for (const combo of combos) {
            for (const value of attribute.values) {
                next.push([
                    ...combo,
                    {
                        attribute_id: attribute.id,
                        attribute_value_id: value.id,
                    },
                ]);
            }
        }

        combos = next;
    }

    const key = (values: VariantValue[]) =>
        [...values]
            .sort((a, b) => a.attribute_id - b.attribute_id)
            .map((v) => `${v.attribute_id}:${v.attribute_value_id}`)
            .join('|');

    const existing = new Map(
        form.variants.map((variant) => [key(variant.values), variant]),
    );

    form.variants = combos.slice(0, 100).map((values) => {
        const found = existing.get(key(values));

        if (found) {
            return found;
        }

        const label = values
            .map((value) =>
                valueLabel(value.attribute_id, value.attribute_value_id),
            )
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

const removeVariant = (index: number) => form.variants.splice(index, 1);

const applyToAllVariants = (field: 'price' | 'stock_quantity') => {
    const first = form.variants[0];

    if (!first) {
        return;
    }

    form.variants.forEach((variant) => {
        if (field === 'price') {
            variant.price = first.price;
        } else {
            variant.stock_quantity = first.stock_quantity;
        }
    });
};

/* ------------------------------------------------------------- computed */
const margin = computed(() => {
    const price = Number(form.price) || 0;
    const cost = Number(form.cost_price) || 0;

    if (!price || !cost) {
        return null;
    }

    return (((price - cost) / price) * 100).toFixed(1);
});

const totalVariantStock = computed(() =>
    form.variants.reduce(
        (sum, variant) => sum + Number(variant.stock_quantity || 0),
        0,
    ),
);

const categoryOptions = computed(() => [
    { value: '', label: 'No category' },
    ...props.categories.map((category) => ({
        value: category.id,
        label: category.parent_id ? `— ${category.name}` : category.name,
    })),
]);

const tabs = [
    { key: 'general', label: 'General' },
    { key: 'variants', label: 'Variants' },
    { key: 'shipping', label: 'Shipping' },
    { key: 'seo', label: 'Search listing' },
] as const;

const variantError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`variants.${index}.${field}`];

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/products/${props.product!.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/admin/products');
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${product!.name}` : 'New product'" />

    <form @submit.prevent="submit">
        <PageHeader
            :title="isEdit ? product!.name : 'Add product'"
            back-href="/admin/products"
        >
            <template #badge>
                <PBadge v-if="isEdit" :tone="statusTone(form.status)" dot>{{
                    titleCase(form.status)
                }}</PBadge>
            </template>
            <template #actions>
                <PButton href="/admin/products">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Save changes' : 'Create product' }}
                </PButton>
            </template>
        </PageHeader>

        <!-- Sales snapshot on edit -->
        <div v-if="isEdit && stats" class="mb-4 grid gap-3 sm:grid-cols-3">
            <div
                class="rounded-xl border border-[#e3e3e3] bg-white px-3.5 py-3 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                    Units sold
                </p>
                <p class="text-lg font-semibold">
                    {{ number(stats.units_sold) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-[#e3e3e3] bg-white px-3.5 py-3 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                    Revenue
                </p>
                <p class="text-lg font-semibold">
                    {{ compactCurrency(stats.revenue) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-[#e3e3e3] bg-white px-3.5 py-3 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">Orders</p>
                <p class="text-lg font-semibold">{{ number(stats.orders) }}</p>
            </div>
        </div>

        <!-- Tabs -->
        <div
            class="mb-4 flex gap-1 overflow-x-auto rounded-xl border border-[#e3e3e3] bg-white p-1 dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
        >
            <button
                v-for="item in tabs"
                :key="item.key"
                type="button"
                class="rounded-lg px-3 py-1.5 text-[13px] font-medium whitespace-nowrap transition"
                :class="
                    tab === item.key
                        ? 'bg-[#f1f1f1] text-[#303030] dark:bg-[#303030] dark:text-white'
                        : 'text-[#616161] hover:bg-[#fafafa] dark:text-[#b5b5b5] dark:hover:bg-[#232323]'
                "
                @click="tab = item.key"
            >
                {{ item.label }}
                <span
                    v-if="item.key === 'variants' && form.variants.length"
                    class="ml-1 text-[#8a8a8a]"
                    >{{ form.variants.length }}</span
                >
            </button>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <!-- GENERAL -->
                <template v-if="tab === 'general'">
                    <PCard title="Product details">
                        <div class="space-y-3">
                            <PTextField
                                v-model="form.name"
                                label="Title"
                                required
                                :error="form.errors.name"
                                placeholder="e.g. Classic Cotton T-Shirt"
                            />
                            <PTextarea
                                v-model="form.short_description"
                                label="Short description"
                                :rows="2"
                                :error="form.errors.short_description"
                                help-text="Shown on listing cards."
                            />
                            <PTextarea
                                v-model="form.description"
                                label="Description"
                                :rows="8"
                                :error="form.errors.description"
                            />
                        </div>
                    </PCard>

                    <PCard
                        title="Media"
                        :subtitle="`${form.images.length} image(s)`"
                    >
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <PTextField
                                    v-model="imageInput"
                                    placeholder="Paste an image URL and press Add"
                                    @keydown.enter.prevent="addImage"
                                />
                            </div>
                            <PButton @click="addImage">Add</PButton>
                        </div>

                        <ul
                            v-if="form.images.length"
                            class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4"
                        >
                            <li
                                v-for="(image, index) in form.images"
                                :key="index"
                                class="group relative overflow-hidden rounded-lg border border-[#e3e3e3] dark:border-[#3a3a3a]"
                            >
                                <img
                                    :src="image.path"
                                    :alt="image.alt"
                                    class="aspect-square w-full object-cover"
                                />
                                <span
                                    v-if="index === 0"
                                    class="absolute top-1 left-1"
                                    ><PBadge tone="info">Cover</PBadge></span
                                >
                                <div
                                    class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-black/60 p-1 opacity-0 transition group-hover:opacity-100"
                                >
                                    <button
                                        type="button"
                                        class="rounded px-1.5 text-xs text-white hover:bg-white/20"
                                        @click="moveImage(index, -1)"
                                    >
                                        ←
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded px-1.5 text-xs text-white hover:bg-white/20"
                                        @click="form.images.splice(index, 1)"
                                    >
                                        Remove
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded px-1.5 text-xs text-white hover:bg-white/20"
                                        @click="moveImage(index, 1)"
                                    >
                                        →
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="mt-3 rounded-lg border border-dashed border-[#d0d0d0] py-6 text-center text-[13px] text-[#8a8a8a] dark:border-[#4a4a4a]"
                        >
                            No images yet. The first image becomes the cover.
                        </p>
                    </PCard>

                    <PCard title="Pricing">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <PTextField
                                v-model="form.price"
                                label="Price"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="₹"
                                required
                                :error="form.errors.price"
                            />
                            <PTextField
                                v-model="form.compare_at_price"
                                label="Compare-at price"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="₹"
                                :error="form.errors.compare_at_price"
                                help-text="Shown struck through."
                            />
                            <PTextField
                                v-model="form.cost_price"
                                label="Cost per item"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="₹"
                                :error="form.errors.cost_price"
                                :help-text="
                                    margin
                                        ? `Margin ${margin}%`
                                        : 'Used to calculate margin.'
                                "
                            />
                        </div>
                    </PCard>

                    <PCard title="Inventory">
                        <div class="space-y-3">
                            <PCheckbox
                                v-model="form.track_inventory"
                                label="Track quantity"
                            />
                            <div
                                v-if="form.track_inventory"
                                class="grid gap-3 sm:grid-cols-3"
                            >
                                <PTextField
                                    v-model="form.stock_quantity"
                                    label="Quantity"
                                    type="number"
                                    min="0"
                                    :disabled="form.type === 'variable'"
                                    :error="form.errors.stock_quantity"
                                    :help-text="
                                        form.type === 'variable'
                                            ? `Summed from variants: ${totalVariantStock}`
                                            : undefined
                                    "
                                />
                                <PTextField
                                    v-model="form.low_stock_threshold"
                                    label="Low stock alert at"
                                    type="number"
                                    min="0"
                                    :error="form.errors.low_stock_threshold"
                                />
                                <div class="flex items-end pb-1.5">
                                    <PCheckbox
                                        v-model="form.allow_backorder"
                                        label="Continue selling when out of stock"
                                    />
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <PTextField
                                    v-model="form.sku"
                                    label="SKU"
                                    :error="form.errors.sku"
                                    placeholder="TSHIRT-BLK-M"
                                />
                                <PTextField
                                    v-model="form.barcode"
                                    label="Barcode (ISBN, UPC, GTIN)"
                                    :error="form.errors.barcode"
                                />
                            </div>
                        </div>
                    </PCard>
                </template>

                <!-- VARIANTS -->
                <template v-else-if="tab === 'variants'">
                    <PCard
                        title="Options"
                        subtitle="Pick the attributes this product varies by"
                    >
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="attribute in attributes"
                                :key="attribute.id"
                                class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 transition"
                                :class="
                                    form.attribute_ids.includes(attribute.id)
                                        ? 'border-[#005bd3] bg-[#f1f8ff] dark:bg-[#002d47]'
                                        : 'border-[#e3e3e3] hover:border-[#b5b5b5] dark:border-[#3a3a3a]'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded accent-[#005bd3]"
                                    :checked="
                                        form.attribute_ids.includes(
                                            attribute.id,
                                        )
                                    "
                                    @change="toggleAttribute(attribute.id)"
                                />
                                <span class="min-w-0">
                                    <span
                                        class="block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                        >{{ attribute.name }}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-[#8a8a8a]"
                                    >
                                        {{
                                            attribute.values
                                                .map((v) => v.value)
                                                .join(', ') || 'No values'
                                        }}
                                    </span>
                                </span>
                            </label>
                        </div>

                        <p
                            v-if="!attributes.length"
                            class="text-[13px] text-[#8a8a8a]"
                        >
                            No attributes exist yet. Create some under Catalog →
                            Attributes.
                        </p>

                        <template #footer>
                            <div class="flex flex-wrap items-center gap-2">
                                <PButton
                                    variant="primary"
                                    size="slim"
                                    :disabled="!variantAttributes.length"
                                    @click="generateVariants"
                                >
                                    Generate variants
                                </PButton>
                                <PButton
                                    v-if="form.variants.length"
                                    size="slim"
                                    variant="plain"
                                    @click="
                                        form.variants = [];
                                        form.type = 'simple';
                                    "
                                >
                                    Clear all variants
                                </PButton>
                                <span class="text-xs text-[#8a8a8a]">
                                    Existing variant prices and stock are
                                    preserved when regenerating.
                                </span>
                            </div>
                        </template>
                    </PCard>

                    <PCard
                        v-if="form.variants.length"
                        :title="`Variants (${form.variants.length})`"
                    >
                        <template #actions>
                            <PButton
                                size="slim"
                                @click="applyToAllVariants('price')"
                                >Apply 1st price to all</PButton
                            >
                            <PButton
                                size="slim"
                                @click="applyToAllVariants('stock_quantity')"
                                >Apply 1st stock to all</PButton
                            >
                        </template>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-max text-left">
                                <thead>
                                    <tr
                                        class="border-b border-[#e3e3e3] text-xs text-[#616161] dark:border-[#3a3a3a] dark:text-[#b5b5b5]"
                                    >
                                        <th class="py-2 pr-3 font-semibold">
                                            Variant
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            SKU
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Price
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Stock
                                        </th>
                                        <th class="px-3 py-2 font-semibold">
                                            Active
                                        </th>
                                        <th class="py-2 pl-3" />
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                                >
                                    <tr
                                        v-for="(
                                            variant, index
                                        ) in form.variants"
                                        :key="index"
                                    >
                                        <td
                                            class="py-2 pr-3 text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                        >
                                            {{ variantTitle(variant) }}
                                        </td>
                                        <td class="w-44 px-3 py-2">
                                            <PTextField
                                                v-model="variant.sku"
                                                placeholder="SKU"
                                                :error="
                                                    variantError(index, 'sku')
                                                "
                                            />
                                        </td>
                                        <td class="w-32 px-3 py-2">
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
                                        <td class="w-28 px-3 py-2">
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
                                            <PCheckbox
                                                v-model="variant.is_active"
                                            />
                                        </td>
                                        <td class="py-2 pl-3">
                                            <button
                                                type="button"
                                                class="rounded-lg p-1.5 text-[#616161] hover:bg-[#ffd6d6] hover:text-[#8e0b21]"
                                                @click="removeVariant(index)"
                                            >
                                                <svg
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                    class="size-4"
                                                >
                                                    <path
                                                        d="M8 2h4a1 1 0 011 1v1h3a1 1 0 110 2h-.3l-.8 10a2 2 0 01-2 1.9H7.1a2 2 0 01-2-1.9L4.3 6H4a1 1 0 010-2h3V3a1 1 0 011-1zm1 4v8h2V6H9z"
                                                    />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <template #footer>
                            <p
                                class="text-xs text-[#616161] dark:text-[#b5b5b5]"
                            >
                                Total stock across variants:
                                <strong>{{ number(totalVariantStock) }}</strong>
                            </p>
                        </template>
                    </PCard>
                </template>

                <!-- SHIPPING -->
                <template v-else-if="tab === 'shipping'">
                    <PCard title="Shipping">
                        <div class="space-y-3">
                            <PCheckbox
                                v-model="form.requires_shipping"
                                label="This is a physical product"
                            />
                            <div
                                v-if="form.requires_shipping"
                                class="grid gap-3 sm:grid-cols-4"
                            >
                                <PTextField
                                    v-model="form.weight"
                                    label="Weight"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    suffix="kg"
                                    :error="form.errors.weight"
                                />
                                <PTextField
                                    v-model="form.length"
                                    label="Length"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    suffix="cm"
                                    :error="form.errors.length"
                                />
                                <PTextField
                                    v-model="form.width"
                                    label="Width"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    suffix="cm"
                                    :error="form.errors.width"
                                />
                                <PTextField
                                    v-model="form.height"
                                    label="Height"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    suffix="cm"
                                    :error="form.errors.height"
                                />
                            </div>
                            <p v-else class="text-[13px] text-[#8a8a8a]">
                                Digital products skip shipping rates at
                                checkout.
                            </p>
                        </div>
                    </PCard>

                    <PCard title="Tax">
                        <PSelect
                            v-model="form.tax_class_id"
                            label="Tax class"
                            :options="[
                                { value: '', label: 'No tax' },
                                ...taxClasses.map((c) => ({
                                    value: c.id,
                                    label: c.name,
                                })),
                            ]"
                            :error="form.errors.tax_class_id"
                            help-text="Rates are configured under Settings → Taxes."
                        />
                    </PCard>
                </template>

                <!-- SEO -->
                <template v-else>
                    <PCard title="Search engine listing">
                        <div class="space-y-3">
                            <PTextField
                                v-model="form.seo_title"
                                label="Page title"
                                :error="form.errors.seo_title"
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
                            />
                        </div>

                        <div
                            class="mt-4 rounded-lg border border-[#e3e3e3] bg-[#fafafa] p-3 dark:border-[#3a3a3a] dark:bg-[#232323]"
                        >
                            <p
                                class="truncate text-[15px] text-[#1a0dab] dark:text-[#8ab4f8]"
                            >
                                {{
                                    form.seo_title ||
                                    form.name ||
                                    'Product title'
                                }}
                            </p>
                            <p
                                class="truncate text-xs text-[#0c6b1d] dark:text-[#7cb342]"
                            >
                                store.example.com/products/{{
                                    form.slug || 'handle'
                                }}
                            </p>
                            <p
                                class="mt-0.5 line-clamp-2 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                            >
                                {{
                                    form.seo_description ||
                                    form.short_description ||
                                    'Add a description to control this snippet.'
                                }}
                            </p>
                        </div>
                    </PCard>
                </template>
            </div>

            <!-- SIDEBAR -->
            <div class="space-y-4">
                <PCard title="Status">
                    <PSelect
                        v-model="form.status"
                        :options="
                            statuses.map((status) => ({
                                value: status,
                                label: titleCase(status),
                            }))
                        "
                        :error="form.errors.status"
                    />
                    <div class="mt-3">
                        <PCheckbox
                            v-model="form.is_featured"
                            label="Featured product"
                            help-text="Highlight on the storefront."
                        />
                    </div>
                </PCard>

                <PCard title="Vendor">
                    <PSelect
                        v-model="form.vendor_id"
                        label="Vendor"
                        :options="[
                            { value: '', label: 'Sold by store' },
                            ...vendors.map((v) => ({
                                value: v.id,
                                label: `${v.name} (${v.commission_rate}%)`,
                            })),
                        ]"
                        :error="form.errors.vendor_id"
                        help-text="Commission is taken from the vendor's rate."
                    />
                </PCard>

                <PCard title="Organisation">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.category_id"
                            label="Primary category"
                            :options="categoryOptions"
                            :error="form.errors.category_id"
                        />
                        <PTextField
                            v-model="form.brand"
                            label="Brand"
                            :error="form.errors.brand"
                        />
                        <PSelect
                            v-model="form.type"
                            label="Product type"
                            :options="[
                                { value: 'simple', label: 'Simple' },
                                {
                                    value: 'variable',
                                    label: 'Variable (has variants)',
                                },
                            ]"
                            :error="form.errors.type"
                        />

                        <div>
                            <label
                                class="mb-1 block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                >Tags</label
                            >
                            <div class="mb-1.5 flex flex-wrap gap-1">
                                <span
                                    v-for="tag in form.tags"
                                    :key="tag"
                                    class="inline-flex items-center gap-1 rounded-md bg-[#f1f1f1] px-2 py-0.5 text-xs text-[#303030] dark:bg-[#303030] dark:text-[#e3e3e3]"
                                >
                                    {{ tag }}
                                    <button
                                        type="button"
                                        class="text-[#8a8a8a] hover:text-[#e51c00]"
                                        @click="removeTag(tag)"
                                    >
                                        ×
                                    </button>
                                </span>
                            </div>
                            <PTextField
                                v-model="tagInput"
                                placeholder="Type and press Enter"
                                @keydown.enter.prevent="addTag"
                            />
                        </div>
                    </div>
                </PCard>

                <PCard title="Additional categories">
                    <div class="max-h-56 space-y-1.5 overflow-y-auto pr-1">
                        <label
                            v-for="category in categories"
                            :key="category.id"
                            class="flex cursor-pointer items-center gap-2"
                        >
                            <input
                                type="checkbox"
                                class="size-4 rounded border-[#8a8a8a] accent-[#303030] dark:accent-[#e3e3e3]"
                                :value="category.id"
                                :checked="
                                    form.category_ids.includes(category.id)
                                "
                                @change="
                                    form.category_ids.includes(category.id)
                                        ? form.category_ids.splice(
                                              form.category_ids.indexOf(
                                                  category.id,
                                              ),
                                              1,
                                          )
                                        : form.category_ids.push(category.id)
                                "
                            />
                            <span
                                class="text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{
                                    category.parent_id
                                        ? `— ${category.name}`
                                        : category.name
                                }}
                            </span>
                        </label>
                    </div>
                </PCard>

                <PCard v-if="Number(form.price) > 0" title="Price summary">
                    <dl class="space-y-1.5 text-[13px]">
                        <div class="flex justify-between">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Selling price
                            </dt>
                            <dd class="font-medium">
                                {{ currency(form.price) }}
                            </dd>
                        </div>
                        <div
                            v-if="form.cost_price"
                            class="flex justify-between"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Cost
                            </dt>
                            <dd>{{ currency(form.cost_price) }}</dd>
                        </div>
                        <div
                            v-if="margin"
                            class="flex justify-between border-t border-[#e3e3e3] pt-1.5 dark:border-[#3a3a3a]"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Margin
                            </dt>
                            <dd
                                class="font-semibold text-[#0c5132] dark:text-[#8ce0b3]"
                            >
                                {{ margin }}%
                            </dd>
                        </div>
                    </dl>
                </PCard>
            </div>
        </div>
    </form>
</template>
