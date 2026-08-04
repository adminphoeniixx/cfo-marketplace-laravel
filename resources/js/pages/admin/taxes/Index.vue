<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { compactCurrency, number } from '@/lib/format';

type TaxRate = {
    id: number;
    tax_class_id: number;
    name: string;
    country: string;
    state: string | null;
    postcode: string | null;
    rate: string;
    priority: number;
    is_compound: boolean;
    applies_to_shipping: boolean;
    is_active: boolean;
};

type TaxClass = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    rates_count: number;
    products_count: number;
    rates: TaxRate[];
};

const props = defineProps<{
    classes: TaxClass[];
    filters: Record<string, string>;
    summary: {
        classes: number;
        rates: number;
        average_rate: number;
        collected: number;
    };
}>();

const showClass = ref(false);
const showRate = ref(false);
const editingClass = ref<TaxClass | null>(null);
const editingRate = ref<TaxRate | null>(null);

const classForm = useForm({
    name: '',
    description: '',
    is_default: false,
    is_active: true,
});
const rateForm = useForm({
    tax_class_id: props.classes[0]?.id ?? 0,
    name: '',
    country: 'IN',
    state: '',
    postcode: '',
    rate: 0,
    priority: 1,
    is_compound: false,
    applies_to_shipping: false,
    is_active: true,
});

const openClass = (taxClass: TaxClass | null) => {
    editingClass.value = taxClass;
    classForm.clearErrors();

    Object.assign(classForm, {
        name: taxClass?.name ?? '',
        description: taxClass?.description ?? '',
        is_default: taxClass?.is_default ?? false,
        is_active: taxClass?.is_active ?? true,
    });

    showClass.value = true;
};

const openRate = (classId: number, rate: TaxRate | null) => {
    editingRate.value = rate;
    rateForm.clearErrors();

    Object.assign(rateForm, {
        tax_class_id: rate?.tax_class_id ?? classId,
        name: rate?.name ?? '',
        country: rate?.country ?? 'IN',
        state: rate?.state ?? '',
        postcode: rate?.postcode ?? '',
        rate: rate?.rate ?? 0,
        priority: rate?.priority ?? 1,
        is_compound: rate?.is_compound ?? false,
        applies_to_shipping: rate?.applies_to_shipping ?? false,
        is_active: rate?.is_active ?? true,
    });

    showRate.value = true;
};

const submitClass = () => {
    const onSuccess = () => (showClass.value = false);

    if (editingClass.value) {
        classForm.put(`/admin/taxes/classes/${editingClass.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        classForm.post('/admin/taxes/classes', {
            preserveScroll: true,
            onSuccess,
        });
    }
};

const submitRate = () => {
    const onSuccess = () => (showRate.value = false);

    if (editingRate.value) {
        rateForm.put(`/admin/taxes/rates/${editingRate.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        rateForm.post('/admin/taxes/rates', {
            preserveScroll: true,
            onSuccess,
        });
    }
};

const deleteClass = (taxClass: TaxClass) =>
    router.delete(`/admin/taxes/classes/${taxClass.id}`, {
        preserveScroll: true,
    });

const deleteRate = (rate: TaxRate) =>
    router.delete(`/admin/taxes/rates/${rate.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Taxes" />

    <PageHeader
        title="Taxes"
        subtitle="Tax classes and the regional rates that apply to them"
    >
        <template #actions>
            <PButton variant="primary" @click="openClass(null)"
                >Add tax class</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <MetricCard label="Tax classes" :value="number(summary.classes)" />
        <MetricCard label="Rates configured" :value="number(summary.rates)" />
        <MetricCard label="Average rate" :value="`${summary.average_rate}%`" />
        <MetricCard
            label="Tax collected"
            :value="compactCurrency(summary.collected)"
            caption="all orders"
        />
    </div>

    <div v-if="classes.length" class="space-y-4">
        <PCard
            v-for="taxClass in classes"
            :key="taxClass.id"
            :title="taxClass.name"
            :subtitle="taxClass.description ?? undefined"
        >
            <template #actions>
                <PBadge v-if="taxClass.is_default" tone="info">Default</PBadge>
                <PBadge
                    :tone="taxClass.is_active ? 'success' : 'neutral'"
                    dot
                    >{{ taxClass.is_active ? 'Active' : 'Inactive' }}</PBadge
                >
                <PButton size="slim" @click="openRate(taxClass.id, null)"
                    >Add rate</PButton
                >
                <PButton size="slim" @click="openClass(taxClass)">Edit</PButton>
                <PButton
                    size="slim"
                    variant="plain"
                    @click="deleteClass(taxClass)"
                    >Delete</PButton
                >
            </template>

            <div v-if="taxClass.rates.length" class="overflow-x-auto">
                <table class="w-full min-w-max text-left text-[13px]">
                    <thead>
                        <tr
                            class="border-b border-[#e3e3e3] text-xs text-[#616161] dark:border-[#3a3a3a] dark:text-[#b5b5b5]"
                        >
                            <th class="py-2 pr-4 font-semibold">Rate name</th>
                            <th class="px-4 py-2 font-semibold">Country</th>
                            <th class="px-4 py-2 font-semibold">State</th>
                            <th class="px-4 py-2 font-semibold">PIN</th>
                            <th class="px-4 py-2 text-right font-semibold">
                                Rate
                            </th>
                            <th class="px-4 py-2 text-right font-semibold">
                                Priority
                            </th>
                            <th class="px-4 py-2 font-semibold">Flags</th>
                            <th class="py-2 pl-4 text-right font-semibold">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                    >
                        <tr v-for="rate in taxClass.rates" :key="rate.id">
                            <td class="py-2 pr-4 font-medium">
                                {{ rate.name }}
                            </td>
                            <td class="px-4 py-2">{{ rate.country }}</td>
                            <td class="px-4 py-2">{{ rate.state || 'All' }}</td>
                            <td class="px-4 py-2">
                                {{ rate.postcode || 'All' }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ rate.rate }}%
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ rate.priority }}
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <PBadge
                                        v-if="rate.is_compound"
                                        tone="attention"
                                        >Compound</PBadge
                                    >
                                    <PBadge
                                        v-if="rate.applies_to_shipping"
                                        tone="new"
                                        >Shipping</PBadge
                                    >
                                    <PBadge
                                        v-if="!rate.is_active"
                                        tone="neutral"
                                        >Inactive</PBadge
                                    >
                                </div>
                            </td>
                            <td class="py-2 pl-4 text-right">
                                <div class="flex justify-end gap-1">
                                    <PButton
                                        size="micro"
                                        @click="openRate(taxClass.id, rate)"
                                        >Edit</PButton
                                    >
                                    <PButton
                                        size="micro"
                                        variant="plain"
                                        @click="deleteRate(rate)"
                                        >Delete</PButton
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                v-else
                class="rounded-lg border border-dashed border-[#d0d0d0] py-6 text-center text-[13px] text-[#8a8a8a] dark:border-[#4a4a4a]"
            >
                No rates in this class yet.
            </p>

            <template #footer>
                <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                    {{ taxClass.products_count }} product(s) use this class ·
                    {{ taxClass.rates_count }} rate(s)
                </p>
            </template>
        </PCard>
    </div>

    <PCard v-else>
        <PEmptyState
            title="No tax classes yet"
            description="Create a class such as “Standard GST” and add regional rates under it."
        >
            <template #action>
                <PButton variant="primary" @click="openClass(null)"
                    >Add tax class</PButton
                >
            </template>
        </PEmptyState>
    </PCard>

    <!-- Class modal -->
    <PModal
        :open="showClass"
        :title="editingClass ? 'Edit tax class' : 'New tax class'"
        size="small"
        @close="showClass = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="classForm.name"
                label="Name"
                required
                :error="classForm.errors.name"
                placeholder="e.g. Standard GST 18%"
            />
            <PTextarea
                v-model="classForm.description"
                label="Description"
                :rows="3"
                :error="classForm.errors.description"
            />
            <PCheckbox
                v-model="classForm.is_default"
                label="Use as default for new products"
            />
            <PCheckbox v-model="classForm.is_active" label="Active" />
        </div>
        <template #footer>
            <PButton @click="showClass = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="classForm.processing"
                @click="submitClass"
            >
                {{ editingClass ? 'Save class' : 'Create class' }}
            </PButton>
        </template>
    </PModal>

    <!-- Rate modal -->
    <PModal
        :open="showRate"
        :title="editingRate ? 'Edit tax rate' : 'New tax rate'"
        size="medium"
        @close="showRate = false"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <PSelect
                    v-model="rateForm.tax_class_id"
                    label="Tax class"
                    :options="
                        classes.map((c) => ({ value: c.id, label: c.name }))
                    "
                    :error="rateForm.errors.tax_class_id"
                />
            </div>
            <div class="sm:col-span-2">
                <PTextField
                    v-model="rateForm.name"
                    label="Rate name"
                    required
                    :error="rateForm.errors.name"
                    placeholder="e.g. IGST 18%"
                />
            </div>
            <PTextField
                v-model="rateForm.country"
                label="Country code"
                required
                :error="rateForm.errors.country"
                help-text="Two letters, e.g. IN"
            />
            <PTextField
                v-model="rateForm.state"
                label="State"
                :error="rateForm.errors.state"
                help-text="Leave blank for all states"
            />
            <PTextField
                v-model="rateForm.postcode"
                label="PIN code"
                :error="rateForm.errors.postcode"
                help-text="Leave blank for all"
            />
            <PTextField
                v-model="rateForm.rate"
                label="Rate"
                type="number"
                step="0.001"
                min="0"
                suffix="%"
                required
                :error="rateForm.errors.rate"
            />
            <PTextField
                v-model="rateForm.priority"
                label="Priority"
                type="number"
                min="1"
                max="10"
                :error="rateForm.errors.priority"
                help-text="Lower runs first"
            />
            <div class="space-y-2 sm:col-span-2">
                <PCheckbox
                    v-model="rateForm.is_compound"
                    label="Compound"
                    help-text="Applied on top of other taxes."
                />
                <PCheckbox
                    v-model="rateForm.applies_to_shipping"
                    label="Also tax shipping"
                />
                <PCheckbox v-model="rateForm.is_active" label="Active" />
            </div>
        </div>
        <template #footer>
            <PButton @click="showRate = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="rateForm.processing"
                @click="submitRate"
            >
                {{ editingRate ? 'Save rate' : 'Add rate' }}
            </PButton>
        </template>
    </PModal>
</template>
