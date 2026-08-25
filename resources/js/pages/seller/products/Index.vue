<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import { currency, number, statusTone, titleCase } from '@/lib/format';

type ProductRow = {
    id: number;
    name: string;
    sku: string | null;
    status: string;
    type: string;
    price: string;
    stock_quantity: number;
    low_stock_threshold: number;
    track_inventory: boolean;
    variants_count: number;
    category: { id: number; name: string } | null;
    images: { id: number; path: string; url: string | null }[];
};

const props = defineProps<{
    products: {
        data: ProductRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    categories: { id: number; name: string }[];
    counts: { all: number; active: number; draft: number; archived: number };
}>();

const selected = ref<number[]>([]);

const allChecked = computed(
    () =>
        props.products.data.length > 0 &&
        selected.value.length === props.products.data.length,
);

const toggleAll = () => {
    selected.value = allChecked.value
        ? []
        : props.products.data.map((product) => product.id);
};

const tabs = computed(() => [
    { value: '', label: 'All', count: props.counts.all },
    { value: 'active', label: 'Active', count: props.counts.active },
    { value: 'draft', label: 'Draft', count: props.counts.draft },
    { value: 'archived', label: 'Archived', count: props.counts.archived },
]);

const runBulk = (action: string) => {
    router.post(
        '/seller/products/bulk',
        { action, ids: selected.value },
        { preserveScroll: true, onSuccess: () => (selected.value = []) },
    );
};

const stockTone = (product: ProductRow) => {
    if (!product.track_inventory) {
        return 'neutral';
    }

    if (product.stock_quantity <= 0) {
        return 'critical';
    }

    return product.stock_quantity <= product.low_stock_threshold
        ? 'warning'
        : 'success';
};

const stockLabel = (product: ProductRow) => {
    if (!product.track_inventory) {
        return 'Not tracked';
    }

    return product.stock_quantity <= 0
        ? 'Out of stock'
        : `${number(product.stock_quantity)} in stock`;
};
</script>

<template>
    <Head title="Products" />

    <PageHeader title="Products" subtitle="Everything your store sells.">
        <template #actions>
            <PButton href="/seller/products/create" variant="primary">
                Add product
            </PButton>
        </template>
    </PageHeader>

    <PCard :padding="false">
        <PFilters
            route-url="/seller/products"
            :filters="filters"
            :tabs="tabs"
            tab-key="status"
            search-placeholder="Search by name or SKU"
        />

        <div
            v-if="selected.length"
            class="flex flex-wrap items-center gap-2 border-b border-[#e3e3e3] bg-[#f7f7f7] px-4 py-2 dark:border-[#3a3a3a] dark:bg-[#232323]"
        >
            <span class="text-[13px] font-medium"
                >{{ selected.length }} selected</span
            >
            <button
                type="button"
                class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                @click="toggleAll"
            >
                {{
                    allChecked
                        ? 'Clear selection'
                        : `Select all ${products.data.length} on this page`
                }}
            </button>
            <div class="ml-auto flex flex-wrap gap-2">
                <PButton size="slim" @click="runBulk('activate')">
                    Set active
                </PButton>
                <PButton size="slim" @click="runBulk('draft')">
                    Set draft
                </PButton>
                <PButton size="slim" @click="runBulk('archive')">
                    Archive
                </PButton>
            </div>
        </div>

        <PTable
            v-if="products.data.length"
            :headers="[
                '',
                'Product',
                'Status',
                'Inventory',
                'Price',
                'Category',
            ]"
            :align-right="[4]"
        >
            <tr
                v-for="product in products.data"
                :key="product.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2">
                    <input
                        v-model="selected"
                        type="checkbox"
                        :value="product.id"
                        class="size-3.5 rounded"
                    />
                </td>
                <td class="px-4 py-2">
                    <Link
                        :href="`/seller/products/${product.id}/edit`"
                        class="flex items-center gap-3"
                    >
                        <img
                            v-if="product.images[0]"
                            :src="
                                product.images[0].url ?? product.images[0].path
                            "
                            :alt="product.name"
                            class="size-9 shrink-0 rounded-md object-cover"
                        />
                        <span
                            v-else
                            class="size-9 shrink-0 rounded-md bg-[#f1f1f1] dark:bg-[#303030]"
                        />
                        <span class="min-w-0">
                            <span
                                class="block max-w-[22rem] truncate text-[13px] font-medium"
                                >{{ product.name }}</span
                            >
                            <span class="block text-xs text-[#8a8a8a]">
                                {{ product.sku || 'No SKU' }}
                                <template v-if="product.variants_count">
                                    · {{ product.variants_count }} variants
                                </template>
                            </span>
                        </span>
                    </Link>
                </td>
                <td class="px-4 py-2">
                    <PBadge :tone="statusTone(product.status)" dot>
                        {{ titleCase(product.status) }}
                    </PBadge>
                </td>
                <td class="px-4 py-2">
                    <PBadge :tone="stockTone(product)">
                        {{ stockLabel(product) }}
                    </PBadge>
                </td>
                <td class="px-4 py-2 text-right text-[13px]">
                    {{ currency(product.price) }}
                </td>
                <td
                    class="px-4 py-2 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ product.category?.name ?? '—' }}
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No products yet"
            description="Add your first product and it goes live the moment you set it active."
        >
            <template #action>
                <PButton href="/seller/products/create" variant="primary">
                    Add product
                </PButton>
            </template>
        </PEmptyState>

        <template #footer>
            <PPagination
                :links="products.links"
                :from="products.from"
                :to="products.to"
                :total="products.total"
            />
        </template>
    </PCard>
</template>
