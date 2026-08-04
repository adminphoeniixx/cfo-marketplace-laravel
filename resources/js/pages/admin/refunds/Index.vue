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

type Refund = {
    id: number;
    number: string;
    type: string;
    reason: string;
    method: string;
    status: string;
    total_amount: string;
    restock: boolean;
    items_count: number;
    created_at: string;
    processed_at: string | null;
    order: { id: number; number: string; grand_total: string } | null;
    customer: { id: number; first_name: string; last_name: string } | null;
};

defineProps<{
    refunds: {
        data: Refund[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    reasons: Record<string, string>;
    methods: Record<string, string>;
    counts: {
        all: number;
        pending: number;
        approved: number;
        processed: number;
        rejected: number;
    };
    summary: {
        pending_value: number;
        processed_value: number;
        this_month: number;
    };
}>();
</script>

<template>
    <Head title="Refunds" />

    <PageHeader
        title="Refunds"
        subtitle="Approve, reject and process money back to customers"
    >
        <template #actions>
            <PButton href="/admin/orders">Browse orders</PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <MetricCard
            label="Awaiting review"
            :value="number(counts.pending)"
            caption="requests"
        />
        <MetricCard
            label="Pending value"
            :value="compactCurrency(summary.pending_value)"
        />
        <MetricCard
            label="Refunded (all time)"
            :value="compactCurrency(summary.processed_value)"
        />
        <MetricCard
            label="Refunded this month"
            :value="compactCurrency(summary.this_month)"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/refunds"
            :filters="filters"
            search-placeholder="Search refund or order number"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'pending', label: 'Pending', count: counts.pending },
                {
                    value: 'approved',
                    label: 'Approved',
                    count: counts.approved,
                },
                {
                    value: 'processed',
                    label: 'Processed',
                    count: counts.processed,
                },
                {
                    value: 'rejected',
                    label: 'Rejected',
                    count: counts.rejected,
                },
            ]"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.reason ?? ''"
                    @change="
                        apply({
                            reason:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All reasons</option>
                    <option
                        v-for="(label, key) in reasons"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.method ?? ''"
                    @change="
                        apply({
                            method:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Any method</option>
                    <option
                        v-for="(label, key) in methods"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
            </template>
        </PFilters>

        <PTable
            v-if="refunds.data.length"
            :headers="[
                'Refund',
                'Order',
                'Customer',
                'Reason',
                'Method',
                'Amount',
                'Status',
                '',
            ]"
            :align-right="[5, 7]"
        >
            <tr
                v-for="row in refunds.data"
                :key="row.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <Link
                        :href="`/admin/refunds/${row.id}`"
                        class="text-[13px] font-semibold text-[#303030] hover:underline dark:text-[#e3e3e3]"
                    >
                        {{ row.number }}
                    </Link>
                    <p class="text-xs text-[#8a8a8a]">
                        {{ date(row.created_at) }} ·
                        {{ row.items_count }} item(s)
                    </p>
                </td>
                <td class="px-4 py-2.5">
                    <Link
                        v-if="row.order"
                        :href="`/admin/orders/${row.order.id}`"
                        class="text-[13px] text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ row.order.number }}
                    </Link>
                    <span v-else class="text-[13px] text-[#8a8a8a]">—</span>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    <Link
                        v-if="row.customer"
                        :href="`/admin/customers/${row.customer.id}`"
                        class="hover:underline"
                    >
                        {{ row.customer.first_name }}
                        {{ row.customer.last_name }}
                    </Link>
                    <span v-else>Guest</span>
                </td>
                <td class="px-4 py-2.5">
                    <span
                        class="text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                        >{{
                            reasons[row.reason] ?? titleCase(row.reason)
                        }}</span
                    >
                    <div class="mt-0.5 flex gap-1">
                        <PBadge tone="new">{{ titleCase(row.type) }}</PBadge>
                        <PBadge v-if="row.restock" tone="info">Restock</PBadge>
                    </div>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ methods[row.method] ?? titleCase(row.method) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ currency(row.total_amount) }}
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(row.status)" dot>{{
                        titleCase(row.status)
                    }}</PBadge>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PButton
                        :href="`/admin/refunds/${row.id}`"
                        size="slim"
                        :variant="
                            ['pending', 'approved'].includes(row.status)
                                ? 'primary'
                                : 'secondary'
                        "
                    >
                        {{
                            row.status === 'pending'
                                ? 'Review'
                                : row.status === 'approved'
                                  ? 'Process'
                                  : 'View'
                        }}
                    </PButton>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No refunds yet"
            description="Refund requests raised from an order will appear here."
        />

        <PPagination
            :links="refunds.links"
            :from="refunds.from"
            :to="refunds.to"
            :total="refunds.total"
        />
    </PCard>
</template>
