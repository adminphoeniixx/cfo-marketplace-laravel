<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { currency, titleCase } from '@/lib/format';

type Variant = {
    id: number;
    name: string | null;
    sku: string | null;
    price: number;
    stock_quantity: number;
};

type Product = {
    id: number;
    name: string;
    sku: string | null;
    price: number;
    stock_quantity: number;
    track_inventory: boolean;
    allow_backorder: boolean;
    tax_rate: number;
    variants: Variant[];
};

type Customer = {
    id: number;
    first_name: string;
    last_name: string | null;
    email: string;
    phone: string | null;
};

const props = defineProps<{
    vendors: { id: number; name: string; commission_rate: string }[];
    selectedVendor: number | null;
    lockedToVendor: boolean;
    products: Product[];
    customers: Customer[];
    statuses: string[];
    paymentStatuses: string[];
    paymentMethods: string[];
}>();

type Line = {
    product_id: number;
    product_variant_id: number | null;
    quantity: number;
    unit_price: number;
};

const form = useForm({
    vendor_id: props.selectedVendor,
    customer_id: null as number | null,
    email: '',
    phone: '',
    status: 'pending',
    payment_status: 'pending',
    payment_method: '',
    shipping_method: '',
    shipping_total: 0,
    discount_total: 0,
    coupon_code: '',
    customer_note: '',
    admin_note: '',
    items: [] as Line[],
});

// Picker state for the "add a line" row.
const pickedProduct = ref<number | null>(null);
const pickedVariant = ref<number | null>(null);
const pickedQuantity = ref(1);

const productFor = (id: number | null) =>
    props.products.find((product) => product.id === Number(id)) ?? null;

const variantsForPicked = computed(
    () => productFor(pickedProduct.value)?.variants ?? [],
);

watch(pickedProduct, () => {
    pickedVariant.value = variantsForPicked.value[0]?.id ?? null;
});

// Switching vendor swaps the catalogue, so any lines already added no longer
// belong to the order — clear them rather than silently mixing vendors.
const changeVendor = (value: string) => {
    form.vendor_id = Number(value);
    form.items = [];
    pickedProduct.value = null;

    router.get(
        '/admin/orders/create',
        { vendor: value },
        {
            only: ['products', 'selectedVendor'],
            preserveState: true,
            preserveScroll: true,
        },
    );
};

const priceOf = (product: Product, variantId: number | null) =>
    variantId
        ? (product.variants.find((variant) => variant.id === Number(variantId))
              ?.price ?? product.price)
        : product.price;

const addLine = () => {
    const product = productFor(pickedProduct.value);

    if (!product) {
        return;
    }

    form.items.push({
        product_id: product.id,
        product_variant_id: pickedVariant.value
            ? Number(pickedVariant.value)
            : null,
        quantity: Math.max(1, Number(pickedQuantity.value) || 1),
        unit_price: priceOf(product, pickedVariant.value),
    });

    pickedProduct.value = null;
    pickedVariant.value = null;
    pickedQuantity.value = 1;
};

const removeLine = (index: number) => form.items.splice(index, 1);

const lineLabel = (line: Line) => {
    const product = productFor(line.product_id);
    const variant = product?.variants.find(
        (v) => v.id === line.product_variant_id,
    );

    return variant?.name
        ? `${product?.name} · ${variant.name}`
        : (product?.name ?? 'Product');
};

const lineTotal = (line: Line) =>
    Number(line.unit_price || 0) * Number(line.quantity || 0);

const subtotal = computed(() =>
    form.items.reduce((sum, line) => sum + lineTotal(line), 0),
);

const taxTotal = computed(() =>
    form.items.reduce(
        (sum, line) =>
            sum +
            (lineTotal(line) * (productFor(line.product_id)?.tax_rate ?? 0)) /
                100,
        0,
    ),
);

const commissionRate = computed(() =>
    Number(
        props.vendors.find((vendor) => vendor.id === Number(form.vendor_id))
            ?.commission_rate ?? 0,
    ),
);

const commissionTotal = computed(
    () => (subtotal.value * commissionRate.value) / 100,
);

const grandTotal = computed(
    () =>
        subtotal.value +
        taxTotal.value +
        Number(form.shipping_total || 0) -
        Number(form.discount_total || 0),
);

// Picking a saved customer prefills contact details, which stay editable.
watch(
    () => form.customer_id,
    (id) => {
        const customer = props.customers.find(
            (entry) => entry.id === Number(id),
        );

        if (customer) {
            form.email = customer.email;
            form.phone = customer.phone ?? '';
        }
    },
);

const submit = () => form.post('/admin/orders', { preserveScroll: true });
</script>

<template>
    <Head title="Create order" />

    <form @submit.prevent="submit">
        <PageHeader
            title="Create order"
            subtitle="Raise an order on behalf of a vendor"
            back-href="/admin/orders"
        >
            <template #actions>
                <PButton href="/admin/orders">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="!form.items.length || form.processing"
                >
                    Create order
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard
                    title="Vendor"
                    subtitle="Every line on this order is billed to the selected vendor"
                >
                    <PSelect
                        :model-value="form.vendor_id"
                        label="Vendor"
                        :disabled="lockedToVendor"
                        :options="
                            vendors.map((v) => ({ value: v.id, label: v.name }))
                        "
                        :error="form.errors.vendor_id"
                        :help-text="
                            lockedToVendor
                                ? 'You can only raise orders for your own store.'
                                : `Commission ${commissionRate}% will be applied to every line.`
                        "
                        @update:model-value="changeVendor"
                    />
                </PCard>

                <PCard title="Products" subtitle="Add the items being ordered">
                    <p
                        v-if="form.errors.items"
                        class="mb-2 text-xs text-[#e51c00]"
                    >
                        {{ form.errors.items }}
                    </p>

                    <div v-if="!products.length">
                        <PEmptyState
                            title="No sellable products"
                            description="This vendor has no active products yet. Add or activate a product first."
                        />
                    </div>

                    <div v-else class="space-y-3">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[200px] flex-1">
                                <PSelect
                                    v-model="pickedProduct"
                                    label="Product"
                                    placeholder="Select a product"
                                    :options="
                                        products.map((product) => ({
                                            value: product.id,
                                            label: product.track_inventory
                                                ? `${product.name} (${product.stock_quantity} in stock)`
                                                : product.name,
                                        }))
                                    "
                                />
                            </div>
                            <div
                                v-if="variantsForPicked.length"
                                class="min-w-[150px]"
                            >
                                <PSelect
                                    v-model="pickedVariant"
                                    label="Variant"
                                    :options="
                                        variantsForPicked.map((variant) => ({
                                            value: variant.id,
                                            label:
                                                variant.name ||
                                                variant.sku ||
                                                `#${variant.id}`,
                                        }))
                                    "
                                />
                            </div>
                            <div class="w-24">
                                <PTextField
                                    v-model="pickedQuantity"
                                    label="Qty"
                                    type="number"
                                    min="1"
                                />
                            </div>
                            <PButton :disabled="!pickedProduct" @click="addLine"
                                >Add item</PButton
                            >
                        </div>

                        <ul
                            v-if="form.items.length"
                            class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                        >
                            <li
                                v-for="(line, index) in form.items"
                                :key="`${line.product_id}-${line.product_variant_id}-${index}`"
                                class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                    >
                                        {{ lineLabel(line) }}
                                    </p>
                                    <p class="text-xs text-[#8a8a8a]">
                                        {{
                                            productFor(line.product_id)
                                                ?.tax_rate ?? 0
                                        }}% tax ·
                                        {{ currency(lineTotal(line)) }} line
                                        total
                                    </p>
                                    <p
                                        v-if="
                                            form.errors[
                                                `items.${index}.quantity`
                                            ]
                                        "
                                        class="text-xs text-[#e51c00]"
                                    >
                                        {{
                                            form.errors[
                                                `items.${index}.quantity`
                                            ]
                                        }}
                                    </p>
                                </div>
                                <div class="w-20 shrink-0">
                                    <PTextField
                                        v-model="line.quantity"
                                        type="number"
                                        min="1"
                                    />
                                </div>
                                <div class="w-28 shrink-0">
                                    <PTextField
                                        v-model="line.unit_price"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        prefix="₹"
                                    />
                                </div>
                                <PButton
                                    size="slim"
                                    variant="critical"
                                    @click="removeLine(index)"
                                >
                                    Remove
                                </PButton>
                            </li>
                        </ul>

                        <p v-else class="text-[13px] text-[#8a8a8a]">
                            No items added yet.
                        </p>
                    </div>
                </PCard>

                <PCard title="Customer" subtitle="Who the order is for">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PSelect
                            v-model="form.customer_id"
                            label="Existing customer"
                            placeholder="Guest / not listed"
                            :options="
                                customers.map((customer) => ({
                                    value: customer.id,
                                    label: `${customer.first_name} ${customer.last_name ?? ''} — ${customer.email}`,
                                }))
                            "
                            :error="form.errors.customer_id"
                            help-text="Leave blank to record a guest order."
                        />
                        <PTextField
                            v-model="form.email"
                            label="Email"
                            type="email"
                            required
                            :error="form.errors.email"
                        />
                        <PTextField
                            v-model="form.phone"
                            label="Phone"
                            :error="form.errors.phone"
                        />
                    </div>
                </PCard>

                <PCard title="Notes">
                    <div class="space-y-3">
                        <PTextarea
                            v-model="form.customer_note"
                            label="Customer note"
                            :rows="2"
                            :error="form.errors.customer_note"
                        />
                        <PTextarea
                            v-model="form.admin_note"
                            label="Internal note"
                            :rows="2"
                            :error="form.errors.admin_note"
                        />
                    </div>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Summary">
                    <dl class="space-y-1.5 text-[13px]">
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Subtotal
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(subtotal) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Tax
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(taxTotal) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Shipping
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(Number(form.shipping_total || 0)) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Discount
                            </dt>
                            <dd class="tabular-nums">
                                −{{
                                    currency(Number(form.discount_total || 0))
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 border-t border-[#e3e3e3] pt-1.5 font-semibold dark:border-[#3a3a3a]"
                        >
                            <dt>Total</dt>
                            <dd class="tabular-nums">
                                {{ currency(grandTotal) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 text-xs text-[#8a8a8a]"
                        >
                            <dt>Commission ({{ commissionRate }}%)</dt>
                            <dd class="tabular-nums">
                                {{ currency(commissionTotal) }}
                            </dd>
                        </div>
                    </dl>
                </PCard>

                <PCard title="Status">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.status"
                            label="Order status"
                            :options="
                                statuses.map((value) => ({
                                    value,
                                    label: titleCase(value),
                                }))
                            "
                            :error="form.errors.status"
                        />
                        <PSelect
                            v-model="form.payment_status"
                            label="Payment status"
                            :options="
                                paymentStatuses.map((value) => ({
                                    value,
                                    label: titleCase(value),
                                }))
                            "
                            :error="form.errors.payment_status"
                        />
                        <PSelect
                            v-model="form.payment_method"
                            label="Payment method"
                            placeholder="Not specified"
                            :options="
                                paymentMethods.map((name) => ({
                                    value: name,
                                    label: name,
                                }))
                            "
                            :error="form.errors.payment_method"
                            help-text="Managed under Settings › Payments."
                        />
                    </div>
                </PCard>

                <PCard title="Shipping &amp; discount">
                    <div class="space-y-3">
                        <PTextField
                            v-model="form.shipping_method"
                            label="Shipping method"
                            placeholder="Standard delivery"
                            :error="form.errors.shipping_method"
                        />
                        <PTextField
                            v-model="form.shipping_total"
                            label="Shipping charge"
                            type="number"
                            min="0"
                            step="0.01"
                            prefix="₹"
                            :error="form.errors.shipping_total"
                        />
                        <PTextField
                            v-model="form.discount_total"
                            label="Discount"
                            type="number"
                            min="0"
                            step="0.01"
                            prefix="₹"
                            :error="form.errors.discount_total"
                        />
                        <PTextField
                            v-model="form.coupon_code"
                            label="Coupon code"
                            :error="form.errors.coupon_code"
                        />
                    </div>
                </PCard>
            </div>
        </div>
    </form>
</template>
