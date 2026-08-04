<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PModal from '@/components/admin/PModal.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import { number } from '@/lib/format';

type Category = {
    id: number;
    name: string;
    slug: string;
    image_path: string | null;
    position: number;
    is_active: boolean;
    is_featured: boolean;
    products_count: number;
    parent: { id: number; name: string } | null;
};

defineProps<{
    categories: {
        data: Category[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    parents: { id: number; name: string }[];
    counts: { all: number; active: number; inactive: number };
}>();

const deleting = ref<Category | null>(null);

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`/admin/categories/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};
</script>

<template>
    <Head title="Categories" />

    <PageHeader
        title="Categories"
        subtitle="Organise your catalog into a browsable tree"
    >
        <template #actions>
            <PButton href="/admin/categories/create" variant="primary"
                >Add category</PButton
            >
        </template>
    </PageHeader>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/categories"
            :filters="filters"
            search-placeholder="Search categories"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'active', label: 'Active', count: counts.active },
                {
                    value: 'inactive',
                    label: 'Inactive',
                    count: counts.inactive,
                },
            ]"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.parent ?? ''"
                    @change="
                        apply({
                            parent:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All parents</option>
                    <option
                        v-for="parent in parents"
                        :key="parent.id"
                        :value="parent.id"
                    >
                        {{ parent.name }}
                    </option>
                </select>
            </template>
        </PFilters>

        <PTable
            v-if="categories.data.length"
            :headers="[
                'Category',
                'Parent',
                'Products',
                'Position',
                'Status',
                '',
            ]"
            :align-right="[2, 3, 5]"
        >
            <tr
                v-for="category in categories.data"
                :key="category.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#f1f1f1] text-xs font-semibold text-[#8a8a8a] dark:bg-[#303030]"
                        >
                            <img
                                v-if="category.image_path"
                                :src="category.image_path"
                                :alt="category.name"
                                class="size-full object-cover"
                            />
                            <template v-else>{{
                                category.name.charAt(0)
                            }}</template>
                        </span>
                        <div class="min-w-0">
                            <Link
                                :href="`/admin/categories/${category.id}/edit`"
                                class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ category.name }}
                            </Link>
                            <p class="truncate text-xs text-[#8a8a8a]">
                                /{{ category.slug }}
                            </p>
                        </div>
                        <PBadge v-if="category.is_featured" tone="info"
                            >Featured</PBadge
                        >
                    </div>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ category.parent?.name ?? '—' }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ number(category.products_count) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    {{ category.position }}
                </td>
                <td class="px-4 py-2.5">
                    <PBadge
                        :tone="category.is_active ? 'success' : 'neutral'"
                        dot
                    >
                        {{ category.is_active ? 'Active' : 'Inactive' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <PButton
                            :href="`/admin/categories/${category.id}/edit`"
                            size="slim"
                            >Edit</PButton
                        >
                        <PButton
                            size="slim"
                            variant="plain"
                            @click="deleting = category"
                            >Delete</PButton
                        >
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No categories found"
            description="Create your first category to start organising products."
        >
            <template #action>
                <PButton href="/admin/categories/create" variant="primary"
                    >Add category</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="categories.links"
            :from="categories.from"
            :to="categories.to"
            :total="categories.total"
        />
    </PCard>

    <PModal
        :open="!!deleting"
        title="Delete category"
        size="small"
        @close="deleting = null"
    >
        <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
            Delete <strong>{{ deleting?.name }}</strong
            >? Products in this category will keep existing but lose their
            category.
        </p>
        <template #footer>
            <PButton @click="deleting = null">Cancel</PButton>
            <PButton variant="critical" @click="confirmDelete">Delete</PButton>
        </template>
    </PModal>
</template>
