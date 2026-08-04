<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PModal from '@/components/admin/PModal.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import {
    currency,
    date,
    relativeDate,
    statusTone,
    titleCase,
} from '@/lib/format';

type OrderItem = {
    id: number;
    name: string;
    sku: string | null;
    image_path: string | null;
    image_url: string | null;
    options: Record<string, string> | null;
    unit_price: string;
    quantity: number;
    quantity_cancelled: number;
    quantity_refunded: number;
    quantity_fulfilled: number;
    tax_amount: string;
    total: string;
    commission_amount: string;
    vendor_earning: string;
    fulfillment_status: string;
    product: { id: number; name: string } | null;
    vendor: { id: number; name: string } | null;
};

type Address = Record<string, string | null> | null;

type Order = {
    id: number;
    number: string;
    email: string;
    phone: string | null;
    status: string;
    payment_status: string;
    fulfillment_status: string;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    refunded_total: string;
    commission_total: string;
    payment_method: string | null;
    transaction_id: string | null;
    shipping_method: string | null;
    tracking_number: string | null;
    carrier: string | null;
    coupon_code: string | null;
    billing_address: Address;
    shipping_address: Address;
    customer_note: string | null;
    admin_note: string | null;
    placed_at: string;
    paid_at: string | null;
    shipped_at: string | null;
    delivered_at: string | null;
    customer: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
        phone: string | null;
        orders_count: number;
        total_spent: string;
    } | null;
    items: OrderItem[];
    events: {
        id: number;
        type: string;
        title: string;
        body: string | null;
        created_at: string;
        user: { name: string } | null;
    }[];
    cancellations: {
        id: number;
        number: string;
        status: string;
        total_amount: string;
        reason: string;
    }[];
    refunds: {
        id: number;
        number: string;
        status: string;
        total_amount: string;
        reason: string;
    }[];
};

const props = defineProps<{
    order: Order;
    statuses: string[];
    paymentStatuses: string[];
    fulfillmentStatuses: string[];
    paymentMethods: string[];
    vendorBreakdown: Record<
        string,
        { items: number; total: number; commission: number; earning: number }
    >;
}>();

const showStatus = ref(false);
const showPayment = ref(false);
const showFulfil = ref(false);

const statusForm = useForm({ status: props.order.status, note: '' });
const paymentForm = useForm({
    payment_status: props.order.payment_status,
    payment_method: props.order.payment_method ?? '',
    transaction_id: props.order.transaction_id ?? '',
});
const noteForm = useForm({ body: '' });
const fulfilForm = useForm({
    items: props.order.items.map((item) => ({
        id: item.id,
        quantity: item.quantity - item.quantity_cancelled,
    })),
    tracking_number: props.order.tracking_number ?? '',
    carrier: props.order.carrier ?? '',
    notify_customer: true,
});

const formatAddress = (address: Address) => {
    if (!address) {
        return [];
    }

    return [
        [address.first_name, address.last_name].filter(Boolean).join(' '),
        address.company,
        address.address_line1,
        address.address_line2,
        [address.city, address.state, address.postcode]
            .filter(Boolean)
            .join(', '),
        address.country,
        address.phone,
    ].filter(Boolean) as string[];
};

const isClosed = computed(() =>
    ['cancelled', 'refunded'].includes(props.order.status),
);
const vendorRows = computed(() => Object.entries(props.vendorBreakdown));

const eventIconTone = (type: string) =>
    ({
        status: 'info',
        payment: 'success',
        fulfillment: 'attention',
        refund: 'critical',
        cancellation: 'critical',
        note: 'neutral',
    })[type] ?? 'neutral';

const submitStatus = () =>
    statusForm.patch(`/admin/orders/${props.order.id}/status`, {
        preserveScroll: true,
        onSuccess: () => (showStatus.value = false),
    });
const submitPayment = () =>
    paymentForm.patch(`/admin/orders/${props.order.id}/payment`, {
        preserveScroll: true,
        onSuccess: () => (showPayment.value = false),
    });
const submitFulfil = () =>
    fulfilForm.post(`/admin/orders/${props.order.id}/fulfill`, {
        preserveScroll: true,
        onSuccess: () => (showFulfil.value = false),
    });
const submitNote = () =>
    noteForm.post(`/admin/orders/${props.order.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <PageHeader
        :title="order.number"
        :subtitle="`Placed ${date(order.placed_at, true)}`"
        back-href="/admin/orders"
    >
        <template #badge>
            <PBadge :tone="statusTone(order.status)" dot>{{
                titleCase(order.status)
            }}</PBadge>
            <PBadge :tone="statusTone(order.payment_status)" dot>{{
                titleCase(order.payment_status)
            }}</PBadge>
            <PBadge :tone="statusTone(order.fulfillment_status)" dot>{{
                titleCase(order.fulfillment_status)
            }}</PBadge>
        </template>
        <template #actions>
            <PButton
                :href="`/admin/cancellations/create?order=${order.id}`"
                :disabled="isClosed"
                >Cancel items</PButton
            >
            <PButton :href="`/admin/refunds/create?order=${order.id}`"
                >Refund</PButton
            >
            <PButton
                variant="primary"
                :disabled="isClosed"
                @click="showFulfil = true"
                >Fulfil items</PButton
            >
        </template>
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <!-- Items -->
            <PCard :title="`Items (${order.items.length})`">
                <template #actions>
                    <PButton size="slim" @click="showFulfil = true"
                        >Update fulfilment</PButton
                    >
                </template>

                <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                    <li
                        v-for="item in order.items"
                        :key="item.id"
                        class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#f1f1f1] text-xs font-semibold text-[#8a8a8a] dark:bg-[#303030]"
                        >
                            <img
                                v-if="item.image_path"
                                :src="item.image_url ?? item.image_path"
                                :alt="item.name"
                                class="size-full object-cover"
                            />
                            <template v-else>{{
                                item.name.charAt(0)
                            }}</template>
                        </span>

                        <div class="min-w-0 flex-1">
                            <Link
                                v-if="item.product"
                                :href="`/admin/products/${item.product.id}/edit`"
                                class="text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ item.name }}
                            </Link>
                            <span v-else class="text-[13px] font-medium">{{
                                item.name
                            }}</span>

                            <p class="text-xs text-[#8a8a8a]">
                                {{ item.sku || 'No SKU' }}
                                <span v-if="item.vendor">
                                    · sold by {{ item.vendor.name }}</span
                                >
                            </p>

                            <p
                                v-if="item.options"
                                class="text-xs text-[#616161] dark:text-[#b5b5b5]"
                            >
                                <span
                                    v-for="(value, key) in item.options"
                                    :key="key"
                                    class="mr-2"
                                    >{{ key }}: {{ value }}</span
                                >
                            </p>

                            <div class="mt-1 flex flex-wrap gap-1">
                                <PBadge
                                    :tone="statusTone(item.fulfillment_status)"
                                    >{{
                                        titleCase(item.fulfillment_status)
                                    }}</PBadge
                                >
                                <PBadge
                                    v-if="item.quantity_cancelled"
                                    tone="critical"
                                    >{{
                                        item.quantity_cancelled
                                    }}
                                    cancelled</PBadge
                                >
                                <PBadge
                                    v-if="item.quantity_refunded"
                                    tone="warning"
                                    >{{
                                        item.quantity_refunded
                                    }}
                                    refunded</PBadge
                                >
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <p
                                class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                            >
                                {{ currency(item.unit_price) }} ×
                                {{ item.quantity }}
                            </p>
                            <p
                                class="text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                            >
                                {{ currency(item.total) }}
                            </p>
                            <p
                                v-if="Number(item.commission_amount) > 0"
                                class="text-xs text-[#8a8a8a]"
                            >
                                comm. {{ currency(item.commission_amount) }}
                            </p>
                        </div>
                    </li>
                </ul>

                <template #footer>
                    <dl class="ml-auto max-w-xs space-y-1.5 text-[13px]">
                        <div class="flex justify-between gap-8">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Subtotal
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(order.subtotal) }}
                            </dd>
                        </div>
                        <div
                            v-if="Number(order.discount_total) > 0"
                            class="flex justify-between gap-8"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Discount
                                <span v-if="order.coupon_code" class="text-xs"
                                    >({{ order.coupon_code }})</span
                                >
                            </dt>
                            <dd
                                class="text-[#0c5132] tabular-nums dark:text-[#8ce0b3]"
                            >
                                −{{ currency(order.discount_total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-8">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Shipping
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(order.shipping_total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-8">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Tax
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(order.tax_total) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-8 border-t border-[#e3e3e3] pt-1.5 font-semibold dark:border-[#3a3a3a]"
                        >
                            <dt>Total</dt>
                            <dd class="tabular-nums">
                                {{ currency(order.grand_total) }}
                            </dd>
                        </div>
                        <div
                            v-if="Number(order.refunded_total) > 0"
                            class="flex justify-between gap-8"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Refunded
                            </dt>
                            <dd class="text-[#e51c00] tabular-nums">
                                −{{ currency(order.refunded_total) }}
                            </dd>
                        </div>
                        <div
                            v-if="Number(order.refunded_total) > 0"
                            class="flex justify-between gap-8 font-semibold"
                        >
                            <dt>Net</dt>
                            <dd class="tabular-nums">
                                {{
                                    currency(
                                        Number(order.grand_total) -
                                            Number(order.refunded_total),
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </template>
            </PCard>

            <!-- Vendor split -->
            <PCard
                v-if="vendorRows.length > 1 || order.commission_total > '0'"
                title="Vendor split"
                subtitle="Earnings by vendor for this order"
            >
                <div class="overflow-x-auto">
                    <table class="w-full min-w-max text-left text-[13px]">
                        <thead>
                            <tr
                                class="border-b border-[#e3e3e3] text-xs text-[#616161] dark:border-[#3a3a3a] dark:text-[#b5b5b5]"
                            >
                                <th class="py-2 pr-4 font-semibold">Vendor</th>
                                <th class="px-4 py-2 text-right font-semibold">
                                    Items
                                </th>
                                <th class="px-4 py-2 text-right font-semibold">
                                    Sales
                                </th>
                                <th class="px-4 py-2 text-right font-semibold">
                                    Commission
                                </th>
                                <th class="py-2 pl-4 text-right font-semibold">
                                    Vendor earns
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                        >
                            <tr v-for="[name, row] in vendorRows" :key="name">
                                <td class="py-2 pr-4 font-medium">
                                    {{ name }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.items }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ currency(row.total) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                                >
                                    {{ currency(row.commission) }}
                                </td>
                                <td
                                    class="py-2 pl-4 text-right font-semibold tabular-nums"
                                >
                                    {{ currency(row.earning) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </PCard>

            <!-- Linked requests -->
            <div
                v-if="order.cancellations.length || order.refunds.length"
                class="grid gap-4 sm:grid-cols-2"
            >
                <PCard v-if="order.cancellations.length" title="Cancellations">
                    <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                        <li
                            v-for="row in order.cancellations"
                            :key="row.id"
                            class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="`/admin/cancellations/${row.id}`"
                                    class="text-[13px] font-medium hover:underline"
                                    >{{ row.number }}</Link
                                >
                                <p class="truncate text-xs text-[#8a8a8a]">
                                    {{ titleCase(row.reason) }}
                                </p>
                            </div>
                            <PBadge :tone="statusTone(row.status)">{{
                                titleCase(row.status)
                            }}</PBadge>
                            <span class="text-[13px] tabular-nums">{{
                                currency(row.total_amount)
                            }}</span>
                        </li>
                    </ul>
                </PCard>

                <PCard v-if="order.refunds.length" title="Refunds">
                    <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                        <li
                            v-for="row in order.refunds"
                            :key="row.id"
                            class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <Link
                                    :href="`/admin/refunds/${row.id}`"
                                    class="text-[13px] font-medium hover:underline"
                                    >{{ row.number }}</Link
                                >
                                <p class="truncate text-xs text-[#8a8a8a]">
                                    {{ titleCase(row.reason) }}
                                </p>
                            </div>
                            <PBadge :tone="statusTone(row.status)">{{
                                titleCase(row.status)
                            }}</PBadge>
                            <span class="text-[13px] tabular-nums">{{
                                currency(row.total_amount)
                            }}</span>
                        </li>
                    </ul>
                </PCard>
            </div>

            <!-- Timeline -->
            <PCard title="Timeline">
                <form class="mb-4 flex gap-2" @submit.prevent="submitNote">
                    <div class="flex-1">
                        <PTextField
                            v-model="noteForm.body"
                            placeholder="Leave a note for your team"
                            :error="noteForm.errors.body"
                        />
                    </div>
                    <PButton
                        type="submit"
                        :loading="noteForm.processing"
                        :disabled="!noteForm.body"
                        >Post</PButton
                    >
                </form>

                <ol
                    class="relative space-y-4 border-l border-[#e3e3e3] pl-5 dark:border-[#3a3a3a]"
                >
                    <li
                        v-for="event in order.events"
                        :key="event.id"
                        class="relative"
                    >
                        <span
                            class="absolute top-1 -left-[26px] flex size-3 items-center justify-center rounded-full ring-4 ring-white dark:ring-[#1a1a1a]"
                            :class="{
                                'bg-[#0094d5]':
                                    eventIconTone(event.type) === 'info',
                                'bg-[#29845a]':
                                    eventIconTone(event.type) === 'success',
                                'bg-[#ffb800]':
                                    eventIconTone(event.type) === 'attention',
                                'bg-[#e51c00]':
                                    eventIconTone(event.type) === 'critical',
                                'bg-[#8a8a8a]':
                                    eventIconTone(event.type) === 'neutral',
                            }"
                        />
                        <p
                            class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ event.title }}
                        </p>
                        <p
                            v-if="event.body"
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{ event.body }}
                        </p>
                        <p class="text-xs text-[#8a8a8a]">
                            {{ relativeDate(event.created_at)
                            }}<span v-if="event.user">
                                · {{ event.user.name }}</span
                            >
                        </p>
                    </li>
                    <li
                        v-if="!order.events.length"
                        class="text-[13px] text-[#8a8a8a]"
                    >
                        No activity recorded yet.
                    </li>
                </ol>
            </PCard>
        </div>

        <!-- SIDEBAR -->
        <div class="space-y-4">
            <PCard title="Order status">
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                            >Status</span
                        >
                        <PBadge :tone="statusTone(order.status)" dot>{{
                            titleCase(order.status)
                        }}</PBadge>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                            >Payment</span
                        >
                        <PBadge :tone="statusTone(order.payment_status)" dot>{{
                            titleCase(order.payment_status)
                        }}</PBadge>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                            >Fulfilment</span
                        >
                        <PBadge
                            :tone="statusTone(order.fulfillment_status)"
                            dot
                            >{{ titleCase(order.fulfillment_status) }}</PBadge
                        >
                    </div>
                </div>
                <template #footer>
                    <div class="flex gap-2">
                        <PButton
                            size="slim"
                            full-width
                            @click="showStatus = true"
                            >Change status</PButton
                        >
                        <PButton
                            size="slim"
                            full-width
                            @click="showPayment = true"
                            >Payment</PButton
                        >
                    </div>
                </template>
            </PCard>

            <PCard title="Customer">
                <div v-if="order.customer">
                    <Link
                        :href="`/admin/customers/${order.customer.id}`"
                        class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ order.customer.first_name }}
                        {{ order.customer.last_name }}
                    </Link>
                    <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                        {{ order.customer.email }}
                    </p>
                    <p
                        v-if="order.customer.phone"
                        class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                    >
                        {{ order.customer.phone }}
                    </p>
                    <p class="mt-2 text-xs text-[#8a8a8a]">
                        {{ order.customer.orders_count }} order(s) ·
                        {{ currency(order.customer.total_spent) }} lifetime
                    </p>
                </div>
                <div v-else>
                    <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                        Guest checkout
                    </p>
                    <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                        {{ order.email }}
                    </p>
                </div>
            </PCard>

            <PCard title="Shipping address">
                <address
                    v-if="order.shipping_address"
                    class="space-y-0.5 text-[13px] text-[#303030] not-italic dark:text-[#e3e3e3]"
                >
                    <p
                        v-for="(line, index) in formatAddress(
                            order.shipping_address,
                        )"
                        :key="index"
                    >
                        {{ line }}
                    </p>
                </address>
                <p v-else class="text-[13px] text-[#8a8a8a]">
                    No shipping address on file.
                </p>
            </PCard>

            <PCard title="Billing address">
                <address
                    v-if="order.billing_address"
                    class="space-y-0.5 text-[13px] text-[#303030] not-italic dark:text-[#e3e3e3]"
                >
                    <p
                        v-for="(line, index) in formatAddress(
                            order.billing_address,
                        )"
                        :key="index"
                    >
                        {{ line }}
                    </p>
                </address>
                <p v-else class="text-[13px] text-[#8a8a8a]">
                    Same as shipping.
                </p>
            </PCard>

            <PCard title="Payment & delivery">
                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Method
                        </dt>
                        <dd class="text-right">
                            {{ order.payment_method ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Transaction
                        </dt>
                        <dd class="truncate text-right">
                            {{ order.transaction_id ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Shipping
                        </dt>
                        <dd class="text-right">
                            {{ order.shipping_method ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Carrier
                        </dt>
                        <dd class="text-right">{{ order.carrier ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Tracking
                        </dt>
                        <dd class="truncate text-right">
                            {{ order.tracking_number ?? '—' }}
                        </dd>
                    </div>
                    <div
                        v-if="order.paid_at"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">Paid</dt>
                        <dd class="text-right">
                            {{ date(order.paid_at, true) }}
                        </dd>
                    </div>
                    <div
                        v-if="order.shipped_at"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Shipped
                        </dt>
                        <dd class="text-right">
                            {{ date(order.shipped_at, true) }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard v-if="order.customer_note" title="Customer note">
                <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                    {{ order.customer_note }}
                </p>
            </PCard>
        </div>
    </div>

    <!-- Status modal -->
    <PModal
        :open="showStatus"
        title="Change order status"
        size="small"
        @close="showStatus = false"
    >
        <div class="space-y-3">
            <PSelect
                v-model="statusForm.status"
                label="Status"
                :options="
                    statuses.map((s) => ({ value: s, label: titleCase(s) }))
                "
                :error="statusForm.errors.status"
            />
            <PTextarea
                v-model="statusForm.note"
                label="Note (optional)"
                :rows="3"
                :error="statusForm.errors.note"
            />
        </div>
        <template #footer>
            <PButton @click="showStatus = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="statusForm.processing"
                @click="submitStatus"
                >Update status</PButton
            >
        </template>
    </PModal>

    <!-- Payment modal -->
    <PModal
        :open="showPayment"
        title="Update payment"
        size="small"
        @close="showPayment = false"
    >
        <div class="space-y-3">
            <PSelect
                v-model="paymentForm.payment_status"
                label="Payment status"
                :options="
                    paymentStatuses.map((s) => ({
                        value: s,
                        label: titleCase(s),
                    }))
                "
                :error="paymentForm.errors.payment_status"
            />
            <PSelect
                v-model="paymentForm.payment_method"
                label="Payment method"
                placeholder="Not specified"
                :options="
                    paymentMethods.map((name) => ({
                        value: name,
                        label: name,
                    }))
                "
                :error="paymentForm.errors.payment_method"
            />
            <PTextField
                v-model="paymentForm.transaction_id"
                label="Transaction reference"
                :error="paymentForm.errors.transaction_id"
            />
        </div>
        <template #footer>
            <PButton @click="showPayment = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="paymentForm.processing"
                @click="submitPayment"
                >Save</PButton
            >
        </template>
    </PModal>

    <!-- Fulfilment modal -->
    <PModal
        :open="showFulfil"
        title="Fulfil items"
        size="large"
        @close="showFulfil = false"
    >
        <div class="space-y-3">
            <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <li
                    v-for="(row, index) in fulfilForm.items"
                    :key="row.id"
                    class="flex items-center gap-3 py-2 first:pt-0"
                >
                    <span
                        class="flex-1 text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                    >
                        {{ order.items[index].name }}
                        <span class="block text-xs text-[#8a8a8a]">
                            ordered {{ order.items[index].quantity }}, cancelled
                            {{ order.items[index].quantity_cancelled }}
                        </span>
                    </span>
                    <div class="w-28">
                        <PTextField
                            v-model="row.quantity"
                            type="number"
                            min="0"
                            :max="
                                order.items[index].quantity -
                                order.items[index].quantity_cancelled
                            "
                        />
                    </div>
                </li>
            </ul>

            <div class="grid gap-3 sm:grid-cols-2">
                <PTextField
                    v-model="fulfilForm.carrier"
                    label="Carrier"
                    placeholder="Delhivery, BlueDart…"
                    :error="fulfilForm.errors.carrier"
                />
                <PTextField
                    v-model="fulfilForm.tracking_number"
                    label="Tracking number"
                    :error="fulfilForm.errors.tracking_number"
                />
            </div>
        </div>
        <template #footer>
            <PButton @click="showFulfil = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="fulfilForm.processing"
                @click="submitFulfil"
                >Save fulfilment</PButton
            >
        </template>
    </PModal>
</template>
