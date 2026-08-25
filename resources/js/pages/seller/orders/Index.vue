<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import { currency, date, number, statusTone, titleCase } from '@/lib/format';

type OrderRow = {
    id: number;
    number: string;
    status: string;
    fulfillment_status: string;
    placed_at: string | null;
    customer: { id: number; first_name: string; last_name: string } | null;
    /** This store's slice of the basket — never the order's grand total. */
    mine: {
        items: number;
        total: number;
        earning: number;
        to_pack: number;
    };
};

const props = defineProps<{
    orders: {
        data: OrderRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    statuses: string[];
    fulfillmentStatuses: string[];
    counts: Record<string, number>;
    summary: { to_pack: number; earnings: number };
}>();

const tabs = computed(() => [
    { value: '', label: 'All', count: props.counts.all },
    ...props.statuses.map((status) => ({
        value: status,
        label: titleCase(status),
        count: props.counts[status],
    })),
]);

const customerName = (order: OrderRow) =>
    order.customer
        ? `${order.customer.first_name} ${order.customer.last_name}`.trim()
        : 'Guest';
</script>

<template>
    <Head title="Orders" />

    <PageHeader
        title="Orders"
        subtitle="Orders containing something from your store."
    >
        <template #actions>
            <PButton href="/seller/orders/create" variant="primary">
                New order
            </PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Still to pack"
            :value="number(summary.to_pack)"
            caption="Orders with your lines outstanding"
        />
        <MetricCard
            label="Your earnings"
            :value="currency(summary.earnings, 0)"
            caption="After commission, excluding cancelled"
        />
        <MetricCard label="Orders" :value="number(orders.total)" />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/seller/orders"
            :filters="filters"
            :tabs="tabs"
            tab-key="status"
            search-placeholder="Search by order number"
        />

        <PTable
            v-if="orders.data.length"
            :headers="[
                'Order',
                'Placed',
                'Customer',
                'Status',
                'Your items',
                'Your total',
                'Your earning',
            ]"
            :align-right="[4, 5, 6]"
        >
            <tr
                v-for="order in orders.data"
                :key="order.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2">
                    <Link
                        :href="`/seller/orders/${order.id}`"
                        class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ order.number }}
                    </Link>
                    <span
                        v-if="order.mine.to_pack"
                        class="ml-2 rounded-full bg-[#ffd6a4] px-1.5 py-0.5 text-[10px] font-semibold text-[#5e4200]"
                    >
                        {{ number(order.mine.to_pack) }} to pack
                    </span>
                </td>
                <td
                    class="px-4 py-2 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ date(order.placed_at) }}
                </td>
                <td class="px-4 py-2 text-[13px]">{{ customerName(order) }}</td>
                <td class="px-4 py-2">
                    <PBadge :tone="statusTone(order.status)" dot>
                        {{ titleCase(order.status) }}
                    </PBadge>
                </td>
                <td class="px-4 py-2 text-right text-[13px]">
                    {{ number(order.mine.items) }}
                </td>
                <td class="px-4 py-2 text-right text-[13px]">
                    {{ currency(order.mine.total) }}
                </td>
                <td class="px-4 py-2 text-right text-[13px] font-medium">
                    {{ currency(order.mine.earning) }}
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No orders yet"
            description="Orders show up here the moment a shopper buys something of yours."
        />

        <template #footer>
            <PPagination
                :links="orders.links"
                :from="orders.from"
                :to="orders.to"
                :total="orders.total"
            />
        </template>
    </PCard>
</template>
