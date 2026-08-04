<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { titleCase } from '@/lib/format';

type AttributeValue = {
    id: number | null;
    value: string;
    color_hex: string | null;
};

type Attribute = {
    id: number;
    name: string;
    slug: string;
    type: string;
    is_variant: boolean;
    is_filterable: boolean;
    is_active: boolean;
    position: number;
    values: AttributeValue[];
};

const props = defineProps<{ attribute: Attribute | null; types: string[] }>();

const isEdit = computed(() => !!props.attribute);

const form = useForm<{
    name: string;
    slug: string;
    type: string;
    is_variant: boolean;
    is_filterable: boolean;
    is_active: boolean;
    position: number;
    values: AttributeValue[];
}>({
    name: props.attribute?.name ?? '',
    slug: props.attribute?.slug ?? '',
    type: props.attribute?.type ?? 'dropdown',
    is_variant: props.attribute?.is_variant ?? true,
    is_filterable: props.attribute?.is_filterable ?? true,
    is_active: props.attribute?.is_active ?? true,
    position: props.attribute?.position ?? 0,
    values: props.attribute?.values.map((value) => ({ ...value })) ?? [
        { id: null, value: '', color_hex: null },
    ],
});

const isSwatch = computed(() => form.type === 'swatch');

const addValue = () =>
    form.values.push({
        id: null,
        value: '',
        color_hex: isSwatch.value ? '#000000' : null,
    });

const removeValue = (index: number) => {
    form.values.splice(index, 1);

    if (!form.values.length) {
        addValue();
    }
};

const valueError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`values.${index}.${field}`];

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/attributes/${props.attribute!.id}`, {
            preserveScroll: true,
        });
    } else {
        form.post('/admin/attributes');
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${attribute!.name}` : 'New attribute'" />

    <form @submit.prevent="submit">
        <PageHeader
            :title="isEdit ? attribute!.name : 'Add attribute'"
            back-href="/admin/attributes"
        >
            <template #actions>
                <PButton href="/admin/attributes">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Save changes' : 'Create attribute' }}
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard title="Details">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="form.name"
                            label="Name"
                            required
                            :error="form.errors.name"
                            placeholder="e.g. Size"
                        />
                        <PTextField
                            v-model="form.slug"
                            label="Handle"
                            :error="form.errors.slug"
                            placeholder="auto-generated"
                        />
                        <PSelect
                            v-model="form.type"
                            label="Display type"
                            :options="
                                types.map((type) => ({
                                    value: type,
                                    label: titleCase(type),
                                }))
                            "
                            :error="form.errors.type"
                            help-text="Swatch shows colour chips on the storefront."
                        />
                        <PTextField
                            v-model="form.position"
                            label="Sort position"
                            type="number"
                            min="0"
                            :error="form.errors.position"
                        />
                    </div>
                </PCard>

                <PCard
                    title="Values"
                    :subtitle="`${form.values.length} value(s)`"
                >
                    <template #actions>
                        <PButton size="slim" @click="addValue"
                            >Add value</PButton
                        >
                    </template>

                    <p
                        v-if="form.errors.values"
                        class="mb-2 text-xs text-[#e51c00]"
                    >
                        {{ form.errors.values }}
                    </p>

                    <ul class="space-y-2">
                        <li
                            v-for="(value, index) in form.values"
                            :key="index"
                            class="flex items-start gap-2 rounded-lg border border-[#e3e3e3] p-2 dark:border-[#3a3a3a]"
                        >
                            <span
                                class="mt-1.5 w-5 shrink-0 text-center text-xs text-[#8a8a8a]"
                                >{{ index + 1 }}</span
                            >

                            <div class="flex-1">
                                <PTextField
                                    v-model="value.value"
                                    placeholder="Value name, e.g. Large"
                                    :error="valueError(index, 'value')"
                                />
                            </div>

                            <div
                                v-if="isSwatch"
                                class="flex w-36 shrink-0 items-center gap-1.5"
                            >
                                <input
                                    type="color"
                                    :value="value.color_hex || '#000000'"
                                    class="size-8 shrink-0 cursor-pointer rounded-lg border border-[#d0d0d0] bg-white p-0.5 dark:border-[#4a4a4a] dark:bg-[#303030]"
                                    @input="
                                        value.color_hex = (
                                            $event.target as HTMLInputElement
                                        ).value
                                    "
                                />
                                <PTextField
                                    v-model="value.color_hex"
                                    placeholder="#000000"
                                    :error="valueError(index, 'color_hex')"
                                />
                            </div>

                            <button
                                type="button"
                                class="mt-1 rounded-lg p-1.5 text-[#616161] transition hover:bg-[#ffd6d6] hover:text-[#8e0b21] dark:text-[#b5b5b5]"
                                aria-label="Remove value"
                                @click="removeValue(index)"
                            >
                                <svg
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    class="size-4"
                                >
                                    <path
                                        d="M8 2h4a1 1 0 011 1v1h3a1 1 0 110 2h-.3l-.8 10a2 2 0 01-2 1.9H7.1a2 2 0 01-2-1.9L4.3 6H4a1 1 0 010-2h3V3a1 1 0 011-1zm1 4v8h2V6H9z"
                                    />
                                </svg>
                            </button>
                        </li>
                    </ul>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Behaviour">
                    <div class="space-y-2.5">
                        <PCheckbox
                            v-model="form.is_variant"
                            label="Use for variants"
                            help-text="Products can generate variant combinations from this attribute."
                        />
                        <PCheckbox
                            v-model="form.is_filterable"
                            label="Show in storefront filters"
                        />
                        <PCheckbox v-model="form.is_active" label="Active" />
                    </div>
                </PCard>

                <PCard title="Preview">
                    <div v-if="isSwatch" class="flex flex-wrap gap-2">
                        <span
                            v-for="(value, index) in form.values.filter(
                                (v) => v.value,
                            )"
                            :key="index"
                            class="flex size-8 items-center justify-center rounded-full ring-1 ring-black/10"
                            :style="{
                                background: value.color_hex || '#e3e3e3',
                            }"
                            :title="value.value"
                        />
                        <p
                            v-if="!form.values.some((v) => v.value)"
                            class="text-[13px] text-[#8a8a8a]"
                        >
                            Add values to preview.
                        </p>
                    </div>
                    <div v-else class="flex flex-wrap gap-1.5">
                        <span
                            v-for="(value, index) in form.values.filter(
                                (v) => v.value,
                            )"
                            :key="index"
                            class="rounded-lg border border-[#d0d0d0] px-2.5 py-1 text-[13px] text-[#303030] dark:border-[#4a4a4a] dark:text-[#e3e3e3]"
                        >
                            {{ value.value }}
                        </span>
                        <p
                            v-if="!form.values.some((v) => v.value)"
                            class="text-[13px] text-[#8a8a8a]"
                        >
                            Add values to preview.
                        </p>
                    </div>
                </PCard>
            </div>
        </div>
    </form>
</template>
