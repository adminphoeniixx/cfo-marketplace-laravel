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

type Cancellation = {
    id: number;
    number: string;
    scope: string;
    reason: string;
    status: string;
    total_amount: string;
    restock: boolean;
    requested_by: string;
    items_count: number;
    created_at: string;
    order: { id: number; number: string; grand_total: string } | null;
    customer: { id: number; first_name: string; last_name: string } | null;
};

defineProps<{
    cancellations: {
        data: Cancellation[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    reasons: Record<string, string>;
    counts: {
        all: number;
        pending: number;
        approved: number;
        rejected: number;
    };
    summary: { pending_value: number; approved_value: number };
}>();
</script>

<template>
    <Head title="Cancellations" />

    <PageHeader
        title="Cancellations"
        subtitle="Review and action order cancellation requests"
    >
        <template #actions>
            <PButton href="/admin/orders">Browse orders</PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Awaiting review"
            :value="number(counts.pending)"
            caption="requests"
        />
        <MetricCard
            label="Pending value"
            :value="compactCurrency(summary.pending_value)"
            caption="at risk"
        />
        <MetricCard
            label="Approved value"
            :value="compactCurrency(summary.approved_value)"
            caption="all time"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/cancellations"
            :filters="filters"
            search-placeholder="Search request or order number"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'pending', label: 'Pending', count: counts.pending },
                {
                    value: 'approved',
                    label: 'Approved',
                    count: counts.approved,
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
            </template>
        </PFilters>

        <PTable
            v-if="cancellations.data.length"
            :headers="[
                'Request',
                'Order',
                'Customer',
                'Reason',
                'Items',
                'Amount',
                'Status',
                '',
            ]"
            :align-right="[4, 5, 7]"
        >
            <tr
                v-for="row in cancellations.data"
                :key="row.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <Link
                        :href="`/admin/cancellations/${row.id}`"
                        class="text-[13px] font-semibold text-[#303030] hover:underline dark:text-[#e3e3e3]"
                    >
                        {{ row.number }}
                    </Link>
                    <p class="text-xs text-[#8a8a8a]">
                        {{ date(row.created_at) }} · by
                        {{ titleCase(row.requested_by) }}
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
                        <PBadge tone="new">{{ titleCase(row.scope) }}</PBadge>
                        <PBadge v-if="row.restock" tone="info">Restock</PBadge>
                    </div>
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    {{ row.items_count }}
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
                        :href="`/admin/cancellations/${row.id}`"
                        size="slim"
                        :variant="
                            row.status === 'pending' ? 'primary' : 'secondary'
                        "
                    >
                        {{ row.status === 'pending' ? 'Review' : 'View' }}
                    </PButton>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No cancellation requests"
            description="When customers or staff cancel items, the requests land here for review."
        />

        <PPagination
            :links="cancellations.links"
            :from="cancellations.from"
            :to="cancellations.to"
            :total="cancellations.total"
        />
    </PCard>
</template>
