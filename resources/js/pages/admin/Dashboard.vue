<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import BarChart from '@/components/admin/BarChart.vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import SalesChart from '@/components/admin/SalesChart.vue';
import {
    compactCurrency,
    currency,
    date,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

type Metric = { value: number; change: number | null };

const props = defineProps<{
    range: number;
    metrics: Record<string, Metric>;
    salesSeries: { label: string; value: number }[];
    statusBreakdown: Record<string, number>;
    topProducts: { name: string; qty: number; revenue: number }[];
    topVendors: {
        id: number;
        name: string;
        revenue: number;
        orders_count: number;
    }[];
    recentOrders: {
        id: number;
        number: string;
        status: string;
        payment_status: string;
        grand_total: number;
        placed_at: string;
        customer: { first_name: string; last_name: string } | null;
    }[];
    lowStock: {
        id: number;
        name: string;
        sku: string | null;
        stock_quantity: number;
        low_stock_threshold: number;
        vendor: { name: string } | null;
    }[];
    pending: {
        cancellations: number;
        refunds: number;
        vendors: number;
        unfulfilled: number;
    };
}>();

const ranges = [
    { value: 7, label: 'Last 7 days' },
    { value: 30, label: 'Last 30 days' },
    { value: 90, label: 'Last 90 days' },
    { value: 365, label: 'Last 12 months' },
];

const setRange = (value: number) =>
    router.get('/admin', { range: value }, { preserveState: false });

const statusChart = computed(() =>
    Object.entries(props.statusBreakdown)
        .map(([label, value]) => ({ label: titleCase(label), value }))
        .sort((a, b) => b.value - a.value),
);

const topProductChart = computed(() =>
    props.topProducts.map((p) => ({ label: p.name, value: Number(p.revenue) })),
);

const actionItems = computed(() =>
    [
        {
            label: 'Orders to fulfil',
            count: props.pending.unfulfilled,
            href: '/admin/orders?fulfillment_status=unfulfilled',
            tone: 'warning' as const,
        },
        {
            label: 'Cancellations to review',
            count: props.pending.cancellations,
            href: '/admin/cancellations?status=pending',
            tone: 'critical' as const,
        },
        {
            label: 'Refunds to review',
            count: props.pending.refunds,
            href: '/admin/refunds?status=pending',
            tone: 'critical' as const,
        },
        {
            label: 'Vendors awaiting approval',
            count: props.pending.vendors,
            href: '/admin/vendors?status=pending',
            tone: 'attention' as const,
        },
    ].filter((item) => item.count > 0),
);
</script>

<template>
    <Head title="Dashboard" />

    <PageHeader
        title="Dashboard"
        :subtitle="`Store performance for the ${ranges.find((r) => r.value === range)?.label.toLowerCase()}`"
    >
        <template #actions>
            <div
                class="flex items-center gap-1 rounded-lg border border-[#d0d0d0] bg-white p-0.5 dark:border-[#4a4a4a] dark:bg-[#303030]"
            >
                <button
                    v-for="option in ranges"
                    :key="option.value"
                    type="button"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                    :class="
                        option.value === range
                            ? 'bg-[#303030] text-white dark:bg-white dark:text-[#1a1a1a]'
                            : 'text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#3a3a3a]'
                    "
                    @click="setRange(option.value)"
                >
                    {{ option.label.replace('Last ', '') }}
                </button>
            </div>
            <PButton href="/admin/products/create" variant="primary"
                >Add product</PButton
            >
        </template>
    </PageHeader>

    <!-- Things that need attention -->
    <div
        v-if="actionItems.length"
        class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
    >
        <Link
            v-for="item in actionItems"
            :key="item.label"
            :href="item.href"
            class="flex items-center justify-between gap-3 rounded-xl border border-[#e3e3e3] bg-white px-3.5 py-3 transition hover:border-[#b5b5b5] hover:shadow-sm dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
        >
            <span
                class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                >{{ item.label }}</span
            >
            <PBadge :tone="item.tone">{{ item.count }}</PBadge>
        </Link>
    </div>

    <!-- Key metrics -->
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <MetricCard
            label="Total sales"
            :value="compactCurrency(metrics.revenue.value)"
            :change="metrics.revenue.change"
            caption="vs prev. period"
        />
        <MetricCard
            label="Orders"
            :value="number(metrics.orders.value)"
            :change="metrics.orders.change"
            caption="vs prev. period"
        />
        <MetricCard
            label="Avg. order value"
            :value="compactCurrency(metrics.average_order.value)"
            :change="metrics.average_order.change"
        />
        <MetricCard
            label="New customers"
            :value="number(metrics.customers.value)"
            :change="metrics.customers.change"
        />
        <MetricCard
            label="Commission earned"
            :value="compactCurrency(metrics.commission.value)"
            caption="marketplace"
        />
        <MetricCard
            label="Refunded"
            :value="compactCurrency(metrics.refunded.value)"
            caption="processed"
        />
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 lg:col-span-2">
            <PCard
                title="Sales over time"
                :subtitle="`Gross revenue, ${ranges.find((r) => r.value === range)?.label.toLowerCase()}`"
            >
                <SalesChart :points="salesSeries" currency :height="240" />
            </PCard>
        </div>

        <PCard title="Orders by status">
            <BarChart v-if="statusChart.length" :points="statusChart" />
            <PEmptyState
                v-else
                title="No orders yet"
                description="Order status breakdown will appear here."
            />
        </PCard>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <PCard
            title="Top products"
            subtitle="By revenue in the selected period"
        >
            <template #actions>
                <PButton href="/admin/products" variant="plain" size="slim"
                    >View all</PButton
                >
            </template>
            <BarChart
                v-if="topProductChart.length"
                :points="topProductChart"
                currency
            />
            <PEmptyState
                v-else
                title="No sales in this period"
                description="Once orders come in, your best sellers show up here."
            />
        </PCard>

        <PCard title="Top vendors" subtitle="Vendor leaderboard">
            <template #actions>
                <PButton href="/admin/vendors" variant="plain" size="slim"
                    >View all</PButton
                >
            </template>

            <ul
                v-if="topVendors.length"
                class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
            >
                <li
                    v-for="(vendor, index) in topVendors"
                    :key="vendor.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#f1f1f1] text-xs font-semibold text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                    >
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/vendors/${vendor.id}`"
                            class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                        >
                            {{ vendor.name }}
                        </Link>
                        <p class="text-xs text-[#8a8a8a]">
                            {{ number(vendor.orders_count) }} orders
                        </p>
                    </div>
                    <span
                        class="text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                    >
                        {{ compactCurrency(vendor.revenue) }}
                    </span>
                </li>
            </ul>
            <PEmptyState
                v-else
                title="No vendor sales yet"
                description="Approve vendors and their sales will rank here."
            />
        </PCard>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <PCard title="Recent orders">
            <template #actions>
                <PButton href="/admin/orders" variant="plain" size="slim"
                    >View all</PButton
                >
            </template>

            <ul
                v-if="recentOrders.length"
                class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
            >
                <li
                    v-for="order in recentOrders"
                    :key="order.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/orders/${order.id}`"
                            class="text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                        >
                            {{ order.number }}
                        </Link>
                        <p class="truncate text-xs text-[#8a8a8a]">
                            {{
                                order.customer
                                    ? `${order.customer.first_name} ${order.customer.last_name}`
                                    : 'Guest'
                            }}
                            · {{ date(order.placed_at) }}
                        </p>
                    </div>
                    <PBadge :tone="statusTone(order.status)" dot>{{
                        titleCase(order.status)
                    }}</PBadge>
                    <span
                        class="w-20 shrink-0 text-right text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                    >
                        {{ currency(order.grand_total, 0) }}
                    </span>
                </li>
            </ul>
            <PEmptyState
                v-else
                title="No orders yet"
                description="New orders will show up here as they arrive."
            />
        </PCard>

        <PCard
            title="Low stock alerts"
            subtitle="Products at or below their threshold"
        >
            <template #actions>
                <PButton
                    href="/admin/products?stock=low"
                    variant="plain"
                    size="slim"
                    >View all</PButton
                >
            </template>

            <ul
                v-if="lowStock.length"
                class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
            >
                <li
                    v-for="product in lowStock"
                    :key="product.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/products/${product.id}/edit`"
                            class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                        >
                            {{ product.name }}
                        </Link>
                        <p class="truncate text-xs text-[#8a8a8a]">
                            {{ product.sku || 'No SKU'
                            }}<span v-if="product.vendor">
                                · {{ product.vendor.name }}</span
                            >
                        </p>
                    </div>
                    <PBadge
                        :tone="
                            product.stock_quantity <= 0 ? 'critical' : 'warning'
                        "
                    >
                        {{ product.stock_quantity }} left
                    </PBadge>
                </li>
            </ul>
            <PEmptyState
                v-else
                title="Stock levels look healthy"
                description="Nothing is below its low-stock threshold."
            />
        </PCard>
    </div>
</template>
