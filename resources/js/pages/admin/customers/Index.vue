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
import { compactCurrency, currency, date, number } from '@/lib/format';

type Customer = {
    id: number;
    first_name: string;
    last_name: string | null;
    email: string;
    phone: string | null;
    status: string;
    accepts_marketing: boolean;
    total_spent: string;
    orders_count: number;
    last_order_at: string | null;
    created_at: string;
};

defineProps<{
    customers: {
        data: Customer[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    counts: { all: number; active: number; blocked: number };
    summary: {
        total_spent: number;
        average_spend: number;
        repeat_customers: number;
    };
}>();

const initials = (customer: Customer) =>
    `${customer.first_name.charAt(0)}${(customer.last_name ?? '').charAt(0)}`.toUpperCase();
</script>

<template>
    <Head title="Customers" />

    <PageHeader
        title="Customers"
        :subtitle="`${number(customers.total)} customer(s)`"
    >
        <template #actions>
            <PButton href="/admin/customers/create" variant="primary"
                >Add customer</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Lifetime revenue"
            :value="compactCurrency(summary.total_spent)"
            caption="all customers"
        />
        <MetricCard
            label="Average spend"
            :value="compactCurrency(summary.average_spend)"
            caption="per customer"
        />
        <MetricCard
            label="Repeat customers"
            :value="number(summary.repeat_customers)"
            caption="more than one order"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/customers"
            :filters="filters"
            search-placeholder="Search name, email or phone"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'active', label: 'Active', count: counts.active },
                { value: 'blocked', label: 'Blocked', count: counts.blocked },
            ]"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.sort ?? ''"
                    @change="
                        apply({
                            sort:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Newest first</option>
                    <option value="spend">Highest spend</option>
                    <option value="orders">Most orders</option>
                    <option value="name">Name A–Z</option>
                </select>
            </template>
        </PFilters>

        <PTable
            v-if="customers.data.length"
            :headers="[
                'Customer',
                'Contact',
                'Orders',
                'Spent',
                'Last order',
                'Status',
                '',
            ]"
            :align-right="[2, 3, 6]"
        >
            <tr
                v-for="customer in customers.data"
                :key="customer.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#e3e3e3] text-xs font-semibold text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                        >
                            {{ initials(customer) }}
                        </span>
                        <div class="min-w-0">
                            <Link
                                :href="`/admin/customers/${customer.id}`"
                                class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ customer.first_name }}
                                {{ customer.last_name }}
                            </Link>
                            <p class="text-xs text-[#8a8a8a]">
                                Joined {{ date(customer.created_at) }}
                            </p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-2.5">
                    <p
                        class="truncate text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                    >
                        {{ customer.email }}
                    </p>
                    <p class="text-xs text-[#8a8a8a]">
                        {{ customer.phone ?? 'No phone' }}
                    </p>
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ customer.orders_count }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ currency(customer.total_spent) }}
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] whitespace-nowrap text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{
                        customer.last_order_at
                            ? date(customer.last_order_at)
                            : '—'
                    }}
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex flex-wrap gap-1">
                        <PBadge
                            :tone="
                                customer.status === 'active'
                                    ? 'success'
                                    : 'critical'
                            "
                            dot
                        >
                            {{
                                customer.status === 'active'
                                    ? 'Active'
                                    : 'Blocked'
                            }}
                        </PBadge>
                        <PBadge v-if="customer.accepts_marketing" tone="new"
                            >Subscribed</PBadge
                        >
                    </div>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PButton
                        :href="`/admin/customers/${customer.id}`"
                        size="slim"
                        >View</PButton
                    >
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No customers found"
            description="Customers appear here once they place an order or you add them manually."
        >
            <template #action>
                <PButton href="/admin/customers/create" variant="primary"
                    >Add customer</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="customers.links"
            :from="customers.from"
            :to="customers.to"
            :total="customers.total"
        />
    </PCard>
</template>
