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
import { titleCase } from '@/lib/format';

type AttributeValue = { id: number; value: string; color_hex: string | null };

type Attribute = {
    id: number;
    name: string;
    slug: string;
    type: string;
    is_variant: boolean;
    is_filterable: boolean;
    is_active: boolean;
    products_count: number;
    values: AttributeValue[];
};

defineProps<{
    attributes: {
        data: Attribute[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    types: string[];
}>();

const deleting = ref<Attribute | null>(null);

const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`/admin/attributes/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};
</script>

<template>
    <Head title="Attributes" />

    <PageHeader
        title="Attributes"
        subtitle="Options like size and colour that build product variants"
    >
        <template #actions>
            <PButton href="/admin/attributes/create" variant="primary"
                >Add attribute</PButton
            >
        </template>
    </PageHeader>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/attributes"
            :filters="filters"
            search-placeholder="Search attributes"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.type ?? ''"
                    @change="
                        apply({
                            type:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All types</option>
                    <option v-for="type in types" :key="type" :value="type">
                        {{ titleCase(type) }}
                    </option>
                </select>
            </template>
        </PFilters>

        <PTable
            v-if="attributes.data.length"
            :headers="['Attribute', 'Values', 'Type', 'Used by', 'Status', '']"
            :align-right="[3, 5]"
        >
            <tr
                v-for="attribute in attributes.data"
                :key="attribute.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5 align-top">
                    <Link
                        :href="`/admin/attributes/${attribute.id}/edit`"
                        class="text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                    >
                        {{ attribute.name }}
                    </Link>
                    <p class="text-xs text-[#8a8a8a]">{{ attribute.slug }}</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <PBadge v-if="attribute.is_variant" tone="info"
                            >Variants</PBadge
                        >
                        <PBadge v-if="attribute.is_filterable" tone="new"
                            >Filterable</PBadge
                        >
                    </div>
                </td>
                <td class="max-w-md px-4 py-2.5 align-top">
                    <div class="flex flex-wrap gap-1">
                        <span
                            v-for="value in attribute.values.slice(0, 8)"
                            :key="value.id"
                            class="inline-flex items-center gap-1 rounded-md border border-[#e3e3e3] bg-[#fafafa] px-1.5 py-0.5 text-xs text-[#303030] dark:border-[#3a3a3a] dark:bg-[#232323] dark:text-[#e3e3e3]"
                        >
                            <span
                                v-if="value.color_hex"
                                class="size-2.5 rounded-full ring-1 ring-black/10"
                                :style="{ background: value.color_hex }"
                            />
                            {{ value.value }}
                        </span>
                        <span
                            v-if="attribute.values.length > 8"
                            class="text-xs text-[#8a8a8a]"
                        >
                            +{{ attribute.values.length - 8 }} more
                        </span>
                        <span
                            v-if="!attribute.values.length"
                            class="text-xs text-[#8a8a8a]"
                            >No values</span
                        >
                    </div>
                </td>
                <td
                    class="px-4 py-2.5 align-top text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ titleCase(attribute.type) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right align-top text-[13px] text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ attribute.products_count }}
                </td>
                <td class="px-4 py-2.5 align-top">
                    <PBadge
                        :tone="attribute.is_active ? 'success' : 'neutral'"
                        dot
                    >
                        {{ attribute.is_active ? 'Active' : 'Inactive' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right align-top">
                    <div class="flex items-center justify-end gap-1">
                        <PButton
                            :href="`/admin/attributes/${attribute.id}/edit`"
                            size="slim"
                            >Edit</PButton
                        >
                        <PButton
                            size="slim"
                            variant="plain"
                            @click="deleting = attribute"
                            >Delete</PButton
                        >
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No attributes yet"
            description="Add attributes like Size or Colour so products can have variants."
        >
            <template #action>
                <PButton href="/admin/attributes/create" variant="primary"
                    >Add attribute</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="attributes.links"
            :from="attributes.from"
            :to="attributes.to"
            :total="attributes.total"
        />
    </PCard>

    <PModal
        :open="!!deleting"
        title="Delete attribute"
        size="small"
        @close="deleting = null"
    >
        <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
            Delete <strong>{{ deleting?.name }}</strong> and all of its values?
            This cannot be undone.
        </p>
        <template #footer>
            <PButton @click="deleting = null">Cancel</PButton>
            <PButton variant="critical" @click="confirmDelete">Delete</PButton>
        </template>
    </PModal>
</template>
