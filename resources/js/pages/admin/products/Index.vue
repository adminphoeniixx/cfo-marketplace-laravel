<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PModal from '@/components/admin/PModal.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import { currency, number, statusTone, titleCase } from '@/lib/format';

type Product = {
    id: number;
    name: string;
    sku: string | null;
    type: string;
    price: string;
    stock_quantity: number;
    low_stock_threshold: number;
    track_inventory: boolean;
    status: string;
    variants_count: number;
    vendor: { id: number; name: string } | null;
    category: { id: number; name: string } | null;
    images: { id: number; path: string; url: string | null }[];
};

const props = defineProps<{
    products: {
        data: Product[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    vendors: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    counts: { all: number; active: number; draft: number; archived: number };
}>();

const selected = ref<number[]>([]);
const deleting = ref<Product | null>(null);

const allSelected = computed(
    () =>
        props.products.data.length > 0 &&
        selected.value.length === props.products.data.length,
);

const toggleAll = () => {
    selected.value = allSelected.value
        ? []
        : props.products.data.map((product) => product.id);
};

const toggle = (id: number) => {
    const index = selected.value.indexOf(id);

    if (index === -1) {
        selected.value.push(id);
    } else {
        selected.value.splice(index, 1);
    }
};

const runBulk = (action: string) => {
    router.post(
        '/admin/products/bulk',
        { action, ids: selected.value },
        { preserveScroll: true, onSuccess: () => (selected.value = []) },
    );
};

const stockLabel = (product: Product) => {
    if (!product.track_inventory) {
        return { text: 'Not tracked', tone: 'neutral' as const };
    }

    if (product.stock_quantity <= 0) {
        return { text: 'Out of stock', tone: 'critical' as const };
    }

    if (product.stock_quantity <= product.low_stock_threshold) {
        return {
            text: `${product.stock_quantity} left`,
            tone: 'warning' as const,
        };
    }

    return {
        text: `${number(product.stock_quantity)} in stock`,
        tone: 'success' as const,
    };
};

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`/admin/products/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};
</script>

<template>
    <Head title="Products" />

    <PageHeader
        title="Products"
        :subtitle="`${number(products.total)} product(s) in your catalog`"
    >
        <template #actions>
            <PButton href="/admin/categories">Manage categories</PButton>
            <PButton href="/admin/products/create" variant="primary"
                >Add product</PButton
            >
        </template>
    </PageHeader>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/products"
            :filters="filters"
            search-placeholder="Search by name, SKU or brand"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'active', label: 'Active', count: counts.active },
                { value: 'draft', label: 'Draft', count: counts.draft },
                {
                    value: 'archived',
                    label: 'Archived',
                    count: counts.archived,
                },
            ]"
        >
            <template #default="{ apply }">
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
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.category ?? ''"
                    @change="
                        apply({
                            category:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All categories</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.stock ?? ''"
                    @change="
                        apply({
                            stock:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">Any stock level</option>
                    <option value="low">Low stock</option>
                    <option value="out">Out of stock</option>
                </select>
            </template>
        </PFilters>

        <!-- Bulk action bar -->
        <div
            v-if="selected.length"
            class="flex flex-wrap items-center gap-2 border-b border-[#e3e3e3] bg-[#f1f8ff] px-4 py-2 dark:border-[#3a3a3a] dark:bg-[#002d47]"
        >
            <span
                class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                >{{ selected.length }} selected</span
            >
            <span class="flex-1" />
            <PButton size="slim" @click="runBulk('activate')"
                >Set active</PButton
            >
            <PButton size="slim" @click="runBulk('draft')">Set draft</PButton>
            <PButton size="slim" @click="runBulk('archive')">Archive</PButton>
            <PButton size="slim" variant="critical" @click="runBulk('delete')"
                >Delete</PButton
            >
        </div>

        <PTable
            v-if="products.data.length"
            :headers="[
                '',
                'Product',
                'Status',
                'Inventory',
                'Vendor',
                'Category',
                'Price',
                '',
            ]"
            :align-right="[6, 7]"
        >
            <tr
                v-for="product in products.data"
                :key="product.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="w-10 px-4 py-2.5">
                    <input
                        type="checkbox"
                        class="size-4 rounded border-[#8a8a8a] accent-[#303030] dark:accent-[#e3e3e3]"
                        :checked="selected.includes(product.id)"
                        @change="toggle(product.id)"
                    />
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#f1f1f1] text-xs font-semibold text-[#8a8a8a] dark:bg-[#303030]"
                        >
                            <img
                                v-if="product.images?.length"
                                :src="
                                    product.images[0].url ??
                                    product.images[0].path
                                "
                                :alt="product.name"
                                class="size-full object-cover"
                            />
                            <template v-else>{{
                                product.name.charAt(0)
                            }}</template>
                        </span>
                        <div class="max-w-xs min-w-0">
                            <Link
                                :href="`/admin/products/${product.id}/edit`"
                                class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ product.name }}
                            </Link>
                            <p class="truncate text-xs text-[#8a8a8a]">
                                {{ product.sku || 'No SKU' }}
                                <span v-if="product.type === 'variable'">
                                    ·
                                    {{ product.variants_count }} variants</span
                                >
                            </p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(product.status)" dot>{{
                        titleCase(product.status)
                    }}</PBadge>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="stockLabel(product).tone">{{
                        stockLabel(product).text
                    }}</PBadge>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    <Link
                        v-if="product.vendor"
                        :href="`/admin/vendors/${product.vendor.id}`"
                        class="hover:underline"
                    >
                        {{ product.vendor.name }}
                    </Link>
                    <span v-else>Store</span>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ product.category?.name ?? '—' }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] font-medium text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ currency(product.price) }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <PButton
                            :href="`/admin/products/${product.id}/edit`"
                            size="slim"
                            >Edit</PButton
                        >
                        <PButton
                            size="slim"
                            variant="plain"
                            @click="deleting = product"
                            >Delete</PButton
                        >
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No products found"
            description="Adjust your filters, or add your first product to get selling."
        >
            <template #action>
                <PButton href="/admin/products/create" variant="primary"
                    >Add product</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="products.links"
            :from="products.from"
            :to="products.to"
            :total="products.total"
        />
    </PCard>

    <div v-if="products.data.length" class="mt-2 flex items-center gap-2 px-1">
        <input
            type="checkbox"
            class="size-4 rounded border-[#8a8a8a] accent-[#303030] dark:accent-[#e3e3e3]"
            :checked="allSelected"
            @change="toggleAll"
        />
        <span class="text-xs text-[#616161] dark:text-[#b5b5b5]"
            >Select all on this page</span
        >
    </div>

    <PModal
        :open="!!deleting"
        title="Delete product"
        size="small"
        @close="deleting = null"
    >
        <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
            Move <strong>{{ deleting?.name }}</strong> to trash? Existing orders
            keep their record of it.
        </p>
        <template #footer>
            <PButton @click="deleting = null">Cancel</PButton>
            <PButton variant="critical" @click="confirmDelete">Delete</PButton>
        </template>
    </PModal>
</template>
