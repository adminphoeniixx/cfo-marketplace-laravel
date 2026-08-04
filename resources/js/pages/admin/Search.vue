<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import { currency, date, number, statusTone, titleCase } from '@/lib/format';

const props = defineProps<{
    term: string;
    results: {
        orders: {
            id: number;
            number: string;
            status: string;
            grand_total: string;
            placed_at: string;
            customer: { first_name: string; last_name: string } | null;
        }[];
        products: {
            id: number;
            name: string;
            sku: string | null;
            price: string;
            status: string;
            stock_quantity: number;
        }[];
        customers: {
            id: number;
            first_name: string;
            last_name: string;
            email: string;
            total_spent: string;
            orders_count: number;
        }[];
        vendors: {
            id: number;
            name: string;
            status: string;
            commission_rate: string;
        }[];
    } | null;
}>();

const total = computed(() =>
    props.results
        ? props.results.orders.length +
          props.results.products.length +
          props.results.customers.length +
          props.results.vendors.length
        : 0,
);
</script>

<template>
    <Head :title="term ? `Search: ${term}` : 'Search'" />

    <PageHeader
        :title="term ? `Results for “${term}”` : 'Search'"
        :subtitle="
            results
                ? `${total} match(es) across orders, products, customers and vendors`
                : 'Use the search bar above to look across your store'
        "
        back-href="/admin"
    />

    <PEmptyState
        v-if="!results"
        title="Nothing searched yet"
        description="Search for an order number, product, customer email or vendor name."
    />

    <PCard v-else-if="total === 0">
        <PEmptyState
            title="No matches"
            :description="`Nothing found for “${term}”. Try a different spelling or a partial term.`"
        />
    </PCard>

    <div v-else class="grid gap-4 lg:grid-cols-2">
        <PCard
            v-if="results.orders.length"
            :title="`Orders (${results.orders.length})`"
        >
            <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <li
                    v-for="order in results.orders"
                    :key="order.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/orders/${order.id}`"
                            class="text-[13px] font-medium hover:underline"
                            >{{ order.number }}</Link
                        >
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
                    <span class="text-[13px] font-semibold tabular-nums">{{
                        currency(order.grand_total, 0)
                    }}</span>
                </li>
            </ul>
        </PCard>

        <PCard
            v-if="results.products.length"
            :title="`Products (${results.products.length})`"
        >
            <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <li
                    v-for="product in results.products"
                    :key="product.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/products/${product.id}/edit`"
                            class="block truncate text-[13px] font-medium hover:underline"
                        >
                            {{ product.name }}
                        </Link>
                        <p class="truncate text-xs text-[#8a8a8a]">
                            {{ product.sku || 'No SKU' }} ·
                            {{ number(product.stock_quantity) }} in stock
                        </p>
                    </div>
                    <PBadge :tone="statusTone(product.status)">{{
                        titleCase(product.status)
                    }}</PBadge>
                    <span class="text-[13px] font-semibold tabular-nums">{{
                        currency(product.price, 0)
                    }}</span>
                </li>
            </ul>
        </PCard>

        <PCard
            v-if="results.customers.length"
            :title="`Customers (${results.customers.length})`"
        >
            <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <li
                    v-for="customer in results.customers"
                    :key="customer.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/admin/customers/${customer.id}`"
                            class="block truncate text-[13px] font-medium hover:underline"
                        >
                            {{ customer.first_name }} {{ customer.last_name }}
                        </Link>
                        <p class="truncate text-xs text-[#8a8a8a]">
                            {{ customer.email }}
                        </p>
                    </div>
                    <span class="text-xs text-[#8a8a8a]"
                        >{{ customer.orders_count }} orders</span
                    >
                    <span class="text-[13px] font-semibold tabular-nums">{{
                        currency(customer.total_spent, 0)
                    }}</span>
                </li>
            </ul>
        </PCard>

        <PCard
            v-if="results.vendors.length"
            :title="`Vendors (${results.vendors.length})`"
        >
            <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <li
                    v-for="vendor in results.vendors"
                    :key="vendor.id"
                    class="flex items-center gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <Link
                        :href="`/admin/vendors/${vendor.id}`"
                        class="min-w-0 flex-1 truncate text-[13px] font-medium hover:underline"
                    >
                        {{ vendor.name }}
                    </Link>
                    <span class="text-xs text-[#8a8a8a]"
                        >{{ vendor.commission_rate }}% commission</span
                    >
                    <PBadge :tone="statusTone(vendor.status)" dot>{{
                        titleCase(vendor.status)
                    }}</PBadge>
                </li>
            </ul>
        </PCard>
    </div>
</template>
