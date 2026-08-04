<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
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
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

type Vendor = {
    id: number;
    name: string;
    slug: string;
    store_email: string | null;
    city: string | null;
    state: string | null;
    status: string;
    commission_type: string;
    commission_rate: string;
    products_count: number;
    payouts_count: number;
    revenue: string | null;
    commission: string | null;
    created_at: string;
};

defineProps<{
    vendors: {
        data: Vendor[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    statuses: string[];
    counts: Record<string, number>;
    summary: { gmv: number; commission: number; payable: number };
}>();

const setStatus = (vendor: Vendor, status: string) =>
    router.patch(
        `/admin/vendors/${vendor.id}/status`,
        {
            status,
            rejection_reason:
                status === 'rejected' ? 'Rejected from list view' : null,
        },
        { preserveScroll: true },
    );
</script>

<template>
    <Head title="Vendors" />

    <PageHeader
        title="Vendors"
        subtitle="Sellers on your marketplace, their commission and performance"
    >
        <template #actions>
            <PButton href="/admin/payouts">Payouts</PButton>
            <PButton href="/admin/vendors/create" variant="primary"
                >Add vendor</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Total GMV"
            :value="compactCurrency(summary.gmv)"
            caption="vendor sales"
        />
        <MetricCard
            label="Commission earned"
            :value="compactCurrency(summary.commission)"
            caption="your cut"
        />
        <MetricCard
            label="Payable to vendors"
            :value="compactCurrency(summary.payable)"
            caption="gross earnings"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/vendors"
            :filters="filters"
            search-placeholder="Search vendor, email or city"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                ...statuses.map((status) => ({
                    value: status,
                    label: titleCase(status),
                    count: counts[status] ?? 0,
                })),
            ]"
        />

        <PTable
            v-if="vendors.data.length"
            :headers="[
                'Vendor',
                'Location',
                'Commission',
                'Products',
                'Revenue',
                'Status',
                '',
            ]"
            :align-right="[3, 4, 6]"
        >
            <tr
                v-for="vendor in vendors.data"
                :key="vendor.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#e3e3e3] text-xs font-semibold text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                        >
                            {{ vendor.name.charAt(0).toUpperCase() }}
                        </span>
                        <div class="min-w-0">
                            <Link
                                :href="`/admin/vendors/${vendor.id}`"
                                class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ vendor.name }}
                            </Link>
                            <p class="truncate text-xs text-[#8a8a8a]">
                                {{ vendor.store_email ?? 'No email' }}
                            </p>
                        </div>
                    </div>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{
                        [vendor.city, vendor.state]
                            .filter(Boolean)
                            .join(', ') || '—'
                    }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{
                        vendor.commission_type === 'percentage'
                            ? `${vendor.commission_rate}%`
                            : currency(vendor.commission_rate)
                    }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    {{ number(vendor.products_count) }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <p
                        class="text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                    >
                        {{ compactCurrency(vendor.revenue ?? 0) }}
                    </p>
                    <p class="text-xs text-[#8a8a8a]">
                        comm. {{ compactCurrency(vendor.commission ?? 0) }}
                    </p>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(vendor.status)" dot>{{
                        titleCase(vendor.status)
                    }}</PBadge>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <PButton
                            v-if="vendor.status === 'pending'"
                            size="slim"
                            variant="primary"
                            @click="setStatus(vendor, 'approved')"
                        >
                            Approve
                        </PButton>
                        <PButton
                            v-if="vendor.status === 'pending'"
                            size="slim"
                            variant="plain"
                            @click="setStatus(vendor, 'rejected')"
                        >
                            Reject
                        </PButton>
                        <PButton
                            :href="`/admin/vendors/${vendor.id}`"
                            size="slim"
                            >View</PButton
                        >
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No vendors found"
            description="Invite sellers, or add a vendor manually to start listing their products."
        >
            <template #action>
                <PButton href="/admin/vendors/create" variant="primary"
                    >Add vendor</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="vendors.links"
            :from="vendors.from"
            :to="vendors.to"
            :total="vendors.total"
        />
    </PCard>
</template>
