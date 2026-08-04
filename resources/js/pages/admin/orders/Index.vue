<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import {
    compactCurrency,
    currency,
    date,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

type Order = {
    id: number;
    number: string;
    email: string;
    status: string;
    payment_status: string;
    fulfillment_status: string;
    grand_total: string;
    items_count: number;
    placed_at: string;
    customer: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    } | null;
};

const props = defineProps<{
    orders: {
        data: Order[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    vendors: { id: number; name: string }[];
    statuses: string[];
    paymentStatuses: string[];
    paymentMethods: string[];
    counts: Record<string, number>;
    summary: { revenue: number; unfulfilled: number; unpaid: number };
}>();

const tabs = [
    { value: '', label: 'All', count: props.counts.all },
    ...props.statuses.map((status) => ({
        value: status,
        label: titleCase(status),
        count: props.counts[status] ?? 0,
    })),
];
</script>

<template>
    <Head title="Orders" />

    <PageHeader title="Orders" :subtitle="`${number(orders.total)} order(s)`">
        <template #actions>
            <PButton href="/admin/cancellations">Cancellations</PButton>
            <PButton href="/admin/refunds">Refunds</PButton>
            <PButton href="/admin/orders/create" variant="primary"
                >Create order</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Gross revenue"
            :value="compactCurrency(summary.revenue)"
            caption="excludes cancelled"
        />
        <MetricCard
            label="Awaiting fulfilment"
            :value="number(summary.unfulfilled)"
            caption="orders"
        />
        <MetricCard
            label="Unpaid"
            :value="number(summary.unpaid)"
            caption="orders"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/orders"
            :filters="filters"
            :tabs="tabs"
            search-placeholder="Search order #, email or tracking"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.payment_status ?? ''"
                    @change="
                        apply({
                            payment_status:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Any payment</option>
                    <option
                        v-for="status in paymentStatuses"
                        :key="status"
                        :value="status"
                    >
                        {{ titleCase(status) }}
                    </option>
                </select>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.payment_method ?? ''"
                    @change="
                        apply({
                            payment_method:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Any method</option>
                    <option
                        v-for="method in paymentMethods"
                        :key="method"
                        :value="method"
                    >
                        {{ method }}
                    </option>
                </select>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.fulfillment_status ?? ''"
                    @change="
                        apply({
                            fulfillment_status:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Any fulfilment</option>
                    <option value="unfulfilled">Unfulfilled</option>
                    <option value="partially_fulfilled">
                        Partially fulfilled
                    </option>
                    <option value="fulfilled">Fulfilled</option>
                </select>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.vendor ?? ''"
                    @change="
                        apply({
                            vendor:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All vendors</option>
                    <option
                        v-for="vendor in vendors"
                        :key="vendor.id"
                        :value="vendor.id"
                    >
                        {{ vendor.name }}
                    </option>
                </select>
                <input
                    type="date"
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.from ?? ''"
                    @change="
                        apply({
                            from:
                                ($event.target as HTMLInputElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                />
                <input
                    type="date"
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.to ?? ''"
                    @change="
                        apply({
                            to:
                                ($event.target as HTMLInputElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                />
            </template>
        </PFilters>

        <PTable
            v-if="orders.data.length"
            :headers="[
                'Order',
                'Date',
                'Customer',
                'Payment',
                'Fulfilment',
                'Items',
                'Status',
                'Total',
            ]"
            :align-right="[5, 7]"
        >
            <tr
                v-for="order in orders.data"
                :key="order.id"
                class="cursor-pointer hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <Link
                        :href="`/admin/orders/${order.id}`"
                        class="text-[13px] font-semibold text-[#303030] hover:underline dark:text-[#e3e3e3]"
                    >
                        {{ order.number }}
                    </Link>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] whitespace-nowrap text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ date(order.placed_at) }}
                </td>
                <td class="px-4 py-2.5">
                    <Link
                        v-if="order.customer"
                        :href="`/admin/customers/${order.customer.id}`"
                        class="text-[13px] text-[#303030] hover:underline dark:text-[#e3e3e3]"
                    >
                        {{ order.customer.first_name }}
                        {{ order.customer.last_name }}
                    </Link>
                    <span v-else class="text-[13px] text-[#8a8a8a]">Guest</span>
                    <p class="truncate text-xs text-[#8a8a8a]">
                        {{ order.email }}
                    </p>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(order.payment_status)" dot>{{
                        titleCase(order.payment_status)
                    }}</PBadge>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(order.fulfillment_status)" dot>{{
                        titleCase(order.fulfillment_status)
                    }}</PBadge>
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    {{ order.items_count }}
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(order.status)" dot>{{
                        titleCase(order.status)
                    }}</PBadge>
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ currency(order.grand_total) }}
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No orders match these filters"
            description="Try clearing the search or choosing a different status."
        />

        <PPagination
            :links="orders.links"
            :from="orders.from"
            :to="orders.to"
            :total="orders.total"
        />
    </PCard>
</template>
