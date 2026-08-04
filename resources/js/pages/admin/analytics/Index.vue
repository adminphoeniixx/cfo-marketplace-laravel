<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BarChart from '@/components/admin/BarChart.vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PTable from '@/components/admin/PTable.vue';
import SalesChart from '@/components/admin/SalesChart.vue';
import {
    compactCurrency,
    currency,
    date,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

type Metric = { value: number; change: number | null } | null;

type VendorRow = {
    id: number;
    name: string;
    orders_count: number;
    units: number;
    gross_sales: number;
    commission: number;
    vendor_earnings: number;
};

const props = defineProps<{
    filters: {
        preset: number | null;
        from: string;
        to: string;
        vendor: number | null;
    };
    ranges: number[];
    vendors: { id: number; name: string }[];
    lockedToVendor: boolean;
    metrics: Record<string, Metric>;
    series: { bucket: string; points: { label: string; value: number }[] };
    byVendor: VendorRow[];
    byCategory: { name: string; units: number; gross_sales: number }[];
    topProducts: {
        name: string;
        sku: string | null;
        units: number;
        gross_sales: number;
    }[];
    topCustomers: {
        name: string;
        email: string;
        orders_count: number;
        spend: number;
    }[];
    statusBreakdown: Record<string, number>;
    paymentBreakdown: Record<string, number>;
    fulfillmentBreakdown: Record<string, number>;
    returns: {
        refunds_count: number;
        refunds_value: number;
        cancellations_count: number;
        cancellations_value: number;
    } | null;
    exports: string[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);

watch(
    () => props.filters,
    (next) => {
        from.value = next.from;
        to.value = next.to;
    },
);

const query = computed(() => ({
    ...(props.filters.preset ? { preset: props.filters.preset } : {}),
    ...(props.filters.preset
        ? {}
        : { from: props.filters.from, to: props.filters.to }),
    ...(props.filters.vendor ? { vendor: props.filters.vendor } : {}),
}));

const reload = (params: Record<string, string | number | undefined>) =>
    router.get('/admin/analytics', params, {
        preserveState: true,
        preserveScroll: true,
    });

const applyPreset = (preset: number) =>
    reload({ preset, vendor: props.filters.vendor ?? undefined });

const applyCustomRange = () =>
    reload({
        from: from.value,
        to: to.value,
        vendor: props.filters.vendor ?? undefined,
    });

const applyVendor = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;

    reload({ ...query.value, vendor: value || undefined });
};

const exportUrl = (report: string) => {
    const params = new URLSearchParams(
        Object.entries(query.value).map(([key, value]) => [key, String(value)]),
    );

    return `/admin/analytics/export/${report}?${params.toString()}`;
};

// The chart is the busiest thing on the page, so it gets the sales figure and
// everything else hangs off the same window.
const chartPoints = computed(() => props.series.points);

const bucketLabel = computed(
    () =>
        ({ day: 'daily', week: 'weekly', month: 'monthly' })[
            props.series.bucket
        ] ?? 'daily',
);

const breakdownRows = (rows: Record<string, number>) =>
    Object.entries(rows).map(([label, value]) => ({ label, value }));

const totalOf = (rows: Record<string, number>) =>
    Object.values(rows).reduce((sum, value) => sum + value, 0);
</script>

<template>
    <Head title="Analytics" />

    <PageHeader
        title="Analytics"
        :subtitle="`${date(filters.from)} – ${date(filters.to)}${lockedToVendor ? ' · your store' : ''}`"
    >
        <template #actions>
            <PButton external :href="exportUrl('orders')">
                Export orders
            </PButton>
            <PButton external :href="exportUrl('vendors')" variant="primary">
                Export vendor report
            </PButton>
        </template>
    </PageHeader>

    <PCard class="mb-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="range in ranges"
                    :key="range"
                    type="button"
                    class="rounded-lg px-2.5 py-1.5 text-[13px] font-medium transition-colors"
                    :class="
                        filters.preset === range
                            ? 'bg-[#303030] text-white dark:bg-white dark:text-[#1a1a1a]'
                            : 'text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#303030]'
                    "
                    @click="applyPreset(range)"
                >
                    {{ range === 365 ? '12 months' : `${range} days` }}
                </button>
            </div>

            <div class="flex items-end gap-2">
                <label class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    <span class="mb-1 block">From</span>
                    <input
                        v-model="from"
                        type="date"
                        class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    />
                </label>
                <label class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    <span class="mb-1 block">To</span>
                    <input
                        v-model="to"
                        type="date"
                        class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    />
                </label>
                <PButton @click="applyCustomRange">Apply</PButton>
            </div>

            <label
                v-if="!lockedToVendor"
                class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
            >
                <span class="mb-1 block">Vendor</span>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.vendor ?? ''"
                    @change="applyVendor"
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
            </label>
        </div>
    </PCard>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
            label="Gross sales"
            :value="compactCurrency(metrics.gross_sales?.value ?? 0)"
            :change="metrics.gross_sales?.change"
            caption="vs previous period"
        />
        <MetricCard
            label="Orders"
            :value="number(metrics.orders?.value ?? 0)"
            :change="metrics.orders?.change"
            caption="vs previous period"
        />
        <MetricCard
            label="Average order"
            :value="currency(metrics.average_order?.value ?? 0)"
            :change="metrics.average_order?.change"
            caption="per order"
        />
        <MetricCard
            label="Units sold"
            :value="number(metrics.units?.value ?? 0)"
            :change="metrics.units?.change"
            caption="items"
        />
        <MetricCard
            label="Commission earned"
            :value="compactCurrency(metrics.commission?.value ?? 0)"
            :change="metrics.commission?.change"
            caption="platform revenue"
        />
        <MetricCard
            label="Vendor earnings"
            :value="compactCurrency(metrics.vendor_earnings?.value ?? 0)"
            :change="metrics.vendor_earnings?.change"
            caption="payable to sellers"
        />
        <MetricCard
            label="Tax collected"
            :value="compactCurrency(metrics.tax?.value ?? 0)"
            :change="metrics.tax?.change"
            caption="on line items"
        />
        <MetricCard
            v-if="metrics.customers"
            label="New customers"
            :value="number(metrics.customers.value)"
            :change="metrics.customers.change"
            caption="signed up"
        />
    </div>

    <PCard
        title="Sales trend"
        :subtitle="`Gross sales, ${bucketLabel}`"
        class="mb-4"
    >
        <SalesChart v-if="chartPoints.length" :points="chartPoints" currency />
        <PEmptyState
            v-else
            title="No sales in this period"
            description="Pick a wider date range to see the trend."
        />
    </PCard>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <PCard
            title="Vendor performance"
            subtitle="Gross sales, commission and payable earnings"
            :padding="false"
        >
            <template #actions>
                <PButton external size="slim" :href="exportUrl('vendors')">
                    CSV
                </PButton>
            </template>

            <PTable
                v-if="byVendor.length"
                :headers="[
                    'Vendor',
                    'Orders',
                    'Units',
                    'Gross',
                    'Commission',
                    'Earnings',
                ]"
                :align-right="[1, 2, 3, 4, 5]"
            >
                <tr
                    v-for="row in byVendor"
                    :key="row.id"
                    class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
                >
                    <td
                        class="px-4 py-2.5 text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                    >
                        {{ row.name }}
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ number(row.orders_count) }}
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ number(row.units) }}
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ currency(row.gross_sales) }}
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ currency(row.commission) }}
                    </td>
                    <td
                        class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums"
                    >
                        {{ currency(row.vendor_earnings) }}
                    </td>
                </tr>
            </PTable>
            <PEmptyState
                v-else
                title="No vendor sales"
                description="Nothing was sold in this period."
            />
        </PCard>

        <PCard
            title="Sales by category"
            subtitle="Where the revenue comes from"
        >
            <template #actions>
                <PButton external size="slim" :href="exportUrl('categories')">
                    CSV
                </PButton>
            </template>

            <BarChart
                v-if="byCategory.length"
                :points="
                    byCategory.map((row) => ({
                        label: row.name,
                        value: row.gross_sales,
                    }))
                "
                currency
            />
            <PEmptyState
                v-else
                title="No category sales"
                description="Products need a category to appear here."
            />
        </PCard>
    </div>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <PCard title="Top products" subtitle="By gross sales" :padding="false">
            <template #actions>
                <PButton external size="slim" :href="exportUrl('products')">
                    CSV
                </PButton>
            </template>

            <PTable
                v-if="topProducts.length"
                :headers="['Product', 'Units', 'Gross']"
                :align-right="[1, 2]"
            >
                <tr
                    v-for="row in topProducts"
                    :key="`${row.name}-${row.sku}`"
                    class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
                >
                    <td class="px-4 py-2.5">
                        <p
                            class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ row.name }}
                        </p>
                        <p class="text-xs text-[#8a8a8a]">
                            {{ row.sku || 'No SKU' }}
                        </p>
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ number(row.units) }}
                    </td>
                    <td
                        class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums"
                    >
                        {{ currency(row.gross_sales) }}
                    </td>
                </tr>
            </PTable>
            <PEmptyState
                v-else
                title="No products sold"
                description="Nothing was sold in this period."
            />
        </PCard>

        <PCard title="Top customers" subtitle="By spend" :padding="false">
            <template #actions>
                <PButton external size="slim" :href="exportUrl('customers')">
                    CSV
                </PButton>
            </template>

            <PTable
                v-if="topCustomers.length"
                :headers="['Customer', 'Orders', 'Spend']"
                :align-right="[1, 2]"
            >
                <tr
                    v-for="row in topCustomers"
                    :key="row.email"
                    class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
                >
                    <td class="px-4 py-2.5">
                        <p
                            class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ row.name }}
                        </p>
                        <p class="text-xs text-[#8a8a8a]">{{ row.email }}</p>
                    </td>
                    <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                        {{ number(row.orders_count) }}
                    </td>
                    <td
                        class="px-4 py-2.5 text-right text-[13px] font-semibold tabular-nums"
                    >
                        {{ currency(row.spend) }}
                    </td>
                </tr>
            </PTable>
            <PEmptyState
                v-else
                title="No customer orders"
                description="Guest orders are not counted here."
            />
        </PCard>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <PCard
            title="Order status"
            :subtitle="`${number(totalOf(statusBreakdown))} orders`"
        >
            <ul class="space-y-2">
                <li
                    v-for="row in breakdownRows(statusBreakdown)"
                    :key="row.label"
                    class="flex items-center justify-between gap-3"
                >
                    <PBadge :tone="statusTone(row.label)">
                        {{ titleCase(row.label) }}
                    </PBadge>
                    <span class="text-[13px] tabular-nums">
                        {{ number(row.value) }}
                    </span>
                </li>
            </ul>
        </PCard>

        <PCard
            title="Payment status"
            :subtitle="`${number(totalOf(paymentBreakdown))} orders`"
        >
            <ul class="space-y-2">
                <li
                    v-for="row in breakdownRows(paymentBreakdown)"
                    :key="row.label"
                    class="flex items-center justify-between gap-3"
                >
                    <PBadge :tone="statusTone(row.label)">
                        {{ titleCase(row.label) }}
                    </PBadge>
                    <span class="text-[13px] tabular-nums">
                        {{ number(row.value) }}
                    </span>
                </li>
            </ul>
        </PCard>

        <PCard
            v-if="returns"
            title="Returns"
            subtitle="Refunds and cancellations"
        >
            <dl class="space-y-1.5 text-[13px]">
                <div class="flex justify-between gap-3">
                    <dt class="text-[#616161] dark:text-[#b5b5b5]">
                        Refunds raised
                    </dt>
                    <dd class="tabular-nums">
                        {{ number(returns.refunds_count) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-[#616161] dark:text-[#b5b5b5]">
                        Refunded value
                    </dt>
                    <dd class="tabular-nums">
                        {{ currency(returns.refunds_value) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-[#616161] dark:text-[#b5b5b5]">
                        Cancellations
                    </dt>
                    <dd class="tabular-nums">
                        {{ number(returns.cancellations_count) }}
                    </dd>
                </div>
                <div
                    class="flex justify-between gap-3 border-t border-[#e3e3e3] pt-1.5 font-semibold dark:border-[#3a3a3a]"
                >
                    <dt>Cancelled value</dt>
                    <dd class="tabular-nums">
                        {{ currency(returns.cancellations_value) }}
                    </dd>
                </div>
            </dl>
        </PCard>

        <PCard
            v-else
            title="Fulfilment"
            :subtitle="`${number(totalOf(fulfillmentBreakdown))} orders`"
        >
            <ul class="space-y-2">
                <li
                    v-for="row in breakdownRows(fulfillmentBreakdown)"
                    :key="row.label"
                    class="flex items-center justify-between gap-3"
                >
                    <PBadge :tone="statusTone(row.label)">
                        {{ titleCase(row.label) }}
                    </PBadge>
                    <span class="text-[13px] tabular-nums">
                        {{ number(row.value) }}
                    </span>
                </li>
            </ul>
        </PCard>
    </div>
</template>
