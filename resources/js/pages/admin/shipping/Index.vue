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
import { compactCurrency, currency, number, titleCase } from '@/lib/format';

type Rate = {
    id: number;
    shipping_zone_id: number;
    vendor_id: number | null;
    name: string;
    type: string;
    rate: string;
    per_item_rate: string;
    per_kg_rate: string;
    min_order_amount: string | null;
    max_order_amount: string | null;
    free_above_amount: string | null;
    delivery_days_min: number | null;
    delivery_days_max: number | null;
    is_active: boolean;
    position: number;
    vendor: { id: number; name: string } | null;
};

type Zone = {
    id: number;
    name: string;
    description: string | null;
    countries: string[] | null;
    states: string[] | null;
    is_active: boolean;
    position: number;
    rates_count: number;
    rates: Rate[];
};

const props = defineProps<{
    zones: Zone[];
    filters: Record<string, string>;
    vendors: { id: number; name: string }[];
    rateTypes: string[];
    summary: {
        zones: number;
        rates: number;
        free_rates: number;
        collected: number;
    };
}>();

const showZone = ref(false);
const showRate = ref(false);
const editingZone = ref<Zone | null>(null);
const editingRate = ref<Rate | null>(null);

const zoneForm = useForm({
    name: '',
    description: '',
    countries: '',
    states: '',
    is_active: true,
    position: 0,
});

const rateForm = useForm({
    shipping_zone_id: props.zones[0]?.id ?? 0,
    vendor_id: null as number | null,
    name: '',
    type: 'flat',
    rate: 0,
    per_item_rate: 0,
    per_kg_rate: 0,
    min_order_amount: null as number | null,
    max_order_amount: null as number | null,
    free_above_amount: null as number | null,
    delivery_days_min: null as number | null,
    delivery_days_max: null as number | null,
    is_active: true,
    position: 0,
});

const openZone = (zone: Zone | null) => {
    editingZone.value = zone;
    zoneForm.clearErrors();

    Object.assign(zoneForm, {
        name: zone?.name ?? '',
        description: zone?.description ?? '',
        countries: (zone?.countries ?? []).join(', '),
        states: (zone?.states ?? []).join(', '),
        is_active: zone?.is_active ?? true,
        position: zone?.position ?? 0,
    });

    showZone.value = true;
};

const openRate = (zoneId: number, rate: Rate | null) => {
    editingRate.value = rate;
    rateForm.clearErrors();

    Object.assign(rateForm, {
        shipping_zone_id: rate?.shipping_zone_id ?? zoneId,
        vendor_id: rate?.vendor_id ?? null,
        name: rate?.name ?? '',
        type: rate?.type ?? 'flat',
        rate: rate?.rate ?? 0,
        per_item_rate: rate?.per_item_rate ?? 0,
        per_kg_rate: rate?.per_kg_rate ?? 0,
        min_order_amount: rate?.min_order_amount ?? null,
        max_order_amount: rate?.max_order_amount ?? null,
        free_above_amount: rate?.free_above_amount ?? null,
        delivery_days_min: rate?.delivery_days_min ?? null,
        delivery_days_max: rate?.delivery_days_max ?? null,
        is_active: rate?.is_active ?? true,
        position: rate?.position ?? 0,
    });

    showRate.value = true;
};

const submitZone = () => {
    const onSuccess = () => (showZone.value = false);

    const transform = (data: Record<string, unknown>) => ({
        ...data,
        countries: String(data.countries ?? '')
            .split(',')
            .map((c) => c.trim().toUpperCase())
            .filter(Boolean),
        states: String(data.states ?? '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean),
    });

    if (editingZone.value) {
        zoneForm
            .transform(transform)
            .put(`/admin/shipping/zones/${editingZone.value.id}`, {
                preserveScroll: true,
                onSuccess,
            });
    } else {
        zoneForm.transform(transform).post('/admin/shipping/zones', {
            preserveScroll: true,
            onSuccess,
        });
    }
};

const submitRate = () => {
    const onSuccess = () => (showRate.value = false);

    if (editingRate.value) {
        rateForm.put(`/admin/shipping/rates/${editingRate.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });
    } else {
        rateForm.post('/admin/shipping/rates', {
            preserveScroll: true,
            onSuccess,
        });
    }
};

const deleteZone = (zone: Zone) =>
    router.delete(`/admin/shipping/zones/${zone.id}`, { preserveScroll: true });
const deleteRate = (rate: Rate) =>
    router.delete(`/admin/shipping/rates/${rate.id}`, { preserveScroll: true });

const rateLabel = (rate: Rate) => {
    switch (rate.type) {
        case 'free':
            return 'Free';
        case 'weight_based':
            return `${currency(rate.rate)} + ${currency(rate.per_kg_rate)}/kg`;
        case 'item_based':
            return `${currency(rate.rate)} + ${currency(rate.per_item_rate)}/item`;
        default:
            return currency(rate.rate);
    }
};

const deliveryLabel = (rate: Rate) => {
    if (rate.delivery_days_min && rate.delivery_days_max) {
        return `${rate.delivery_days_min}–${rate.delivery_days_max} days`;
    }

    if (rate.delivery_days_max) {
        return `up to ${rate.delivery_days_max} days`;
    }

    return '—';
};
</script>

<template>
    <Head title="Shipping" />

    <PageHeader
        title="Shipping"
        subtitle="Zones, rates and per-vendor delivery pricing"
    >
        <template #actions>
            <PButton variant="primary" @click="openZone(null)"
                >Add zone</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <MetricCard label="Zones" :value="number(summary.zones)" />
        <MetricCard label="Rates" :value="number(summary.rates)" />
        <MetricCard
            label="Free shipping rates"
            :value="number(summary.free_rates)"
        />
        <MetricCard
            label="Shipping collected"
            :value="compactCurrency(summary.collected)"
            caption="all orders"
        />
    </div>

    <div v-if="zones.length" class="space-y-4">
        <PCard
            v-for="zone in zones"
            :key="zone.id"
            :title="zone.name"
            :subtitle="zone.description ?? undefined"
        >
            <template #actions>
                <PBadge :tone="zone.is_active ? 'success' : 'neutral'" dot>{{
                    zone.is_active ? 'Active' : 'Inactive'
                }}</PBadge>
                <PButton size="slim" @click="openRate(zone.id, null)"
                    >Add rate</PButton
                >
                <PButton size="slim" @click="openZone(zone)">Edit</PButton>
                <PButton size="slim" variant="plain" @click="deleteZone(zone)"
                    >Delete</PButton
                >
            </template>

            <div class="mb-3 flex flex-wrap gap-1">
                <PBadge
                    v-for="country in zone.countries ?? []"
                    :key="country"
                    tone="new"
                    >{{ country }}</PBadge
                >
                <PBadge
                    v-for="state in zone.states ?? []"
                    :key="state"
                    tone="info"
                    >{{ state }}</PBadge
                >
                <span
                    v-if="!zone.countries?.length && !zone.states?.length"
                    class="text-xs text-[#8a8a8a]"
                >
                    Applies everywhere
                </span>
            </div>

            <div v-if="zone.rates.length" class="overflow-x-auto">
                <table class="w-full min-w-max text-left text-[13px]">
                    <thead>
                        <tr
                            class="border-b border-[#e3e3e3] text-xs text-[#616161] dark:border-[#3a3a3a] dark:text-[#b5b5b5]"
                        >
                            <th class="py-2 pr-4 font-semibold">Rate</th>
                            <th class="px-4 py-2 font-semibold">Type</th>
                            <th class="px-4 py-2 font-semibold">Vendor</th>
                            <th class="px-4 py-2 text-right font-semibold">
                                Cost
                            </th>
                            <th class="px-4 py-2 font-semibold">Free above</th>
                            <th class="px-4 py-2 font-semibold">Delivery</th>
                            <th class="py-2 pl-4 text-right font-semibold">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                    >
                        <tr v-for="rate in zone.rates" :key="rate.id">
                            <td class="py-2 pr-4">
                                <span class="font-medium">{{ rate.name }}</span>
                                <PBadge
                                    v-if="!rate.is_active"
                                    tone="neutral"
                                    class="ml-1"
                                    >Inactive</PBadge
                                >
                            </td>
                            <td class="px-4 py-2">
                                {{ titleCase(rate.type) }}
                            </td>
                            <td
                                class="px-4 py-2 text-[#616161] dark:text-[#b5b5b5]"
                            >
                                {{ rate.vendor?.name ?? 'All vendors' }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-semibold tabular-nums"
                            >
                                {{ rateLabel(rate) }}
                            </td>
                            <td class="px-4 py-2 tabular-nums">
                                {{
                                    rate.free_above_amount
                                        ? currency(rate.free_above_amount)
                                        : '—'
                                }}
                            </td>
                            <td class="px-4 py-2">{{ deliveryLabel(rate) }}</td>
                            <td class="py-2 pl-4 text-right">
                                <div class="flex justify-end gap-1">
                                    <PButton
                                        size="micro"
                                        @click="openRate(zone.id, rate)"
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
                No rates in this zone yet.
            </p>
        </PCard>
    </div>

    <PCard v-else>
        <PEmptyState
            title="No shipping zones"
            description="Create a zone (e.g. “Domestic – India”) and add rates for it."
        >
            <template #action>
                <PButton variant="primary" @click="openZone(null)"
                    >Add zone</PButton
                >
            </template>
        </PEmptyState>
    </PCard>

    <!-- Zone modal -->
    <PModal
        :open="showZone"
        :title="editingZone ? 'Edit zone' : 'New shipping zone'"
        size="medium"
        @close="showZone = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="zoneForm.name"
                label="Zone name"
                required
                :error="zoneForm.errors.name"
                placeholder="e.g. Domestic – India"
            />
            <PTextarea
                v-model="zoneForm.description"
                label="Description"
                :rows="2"
                :error="zoneForm.errors.description"
            />
            <PTextField
                v-model="zoneForm.countries"
                label="Countries"
                :error="zoneForm.errors.countries"
                help-text="Comma separated two-letter codes, e.g. IN, LK"
            />
            <PTextField
                v-model="zoneForm.states"
                label="States"
                :error="zoneForm.errors.states"
                help-text="Comma separated. Leave blank for the whole country."
            />
            <PTextField
                v-model="zoneForm.position"
                label="Sort position"
                type="number"
                min="0"
                :error="zoneForm.errors.position"
            />
            <PCheckbox v-model="zoneForm.is_active" label="Active" />
        </div>
        <template #footer>
            <PButton @click="showZone = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="zoneForm.processing"
                @click="submitZone"
            >
                {{ editingZone ? 'Save zone' : 'Create zone' }}
            </PButton>
        </template>
    </PModal>

    <!-- Rate modal -->
    <PModal
        :open="showRate"
        :title="editingRate ? 'Edit rate' : 'New shipping rate'"
        size="large"
        @close="showRate = false"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <PSelect
                v-model="rateForm.shipping_zone_id"
                label="Zone"
                :options="zones.map((z) => ({ value: z.id, label: z.name }))"
                :error="rateForm.errors.shipping_zone_id"
            />
            <PSelect
                v-model="rateForm.vendor_id"
                label="Vendor"
                :options="[
                    { value: '', label: 'All vendors' },
                    ...vendors.map((v) => ({ value: v.id, label: v.name })),
                ]"
                :error="rateForm.errors.vendor_id"
                help-text="Restrict this rate to one vendor's items."
            />
            <PTextField
                v-model="rateForm.name"
                label="Rate name"
                required
                :error="rateForm.errors.name"
                placeholder="e.g. Standard delivery"
            />
            <PSelect
                v-model="rateForm.type"
                label="Calculation"
                :options="
                    rateTypes.map((type) => ({
                        value: type,
                        label: titleCase(type),
                    }))
                "
                :error="rateForm.errors.type"
            />
            <PTextField
                v-model="rateForm.rate"
                label="Base rate"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.rate"
            />
            <PTextField
                v-model="rateForm.free_above_amount"
                label="Free above order value"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.free_above_amount"
            />
            <PTextField
                v-if="rateForm.type === 'weight_based'"
                v-model="rateForm.per_kg_rate"
                label="Per kg"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.per_kg_rate"
            />
            <PTextField
                v-if="rateForm.type === 'item_based'"
                v-model="rateForm.per_item_rate"
                label="Per item"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.per_item_rate"
            />
            <PTextField
                v-model="rateForm.min_order_amount"
                label="Min order value"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.min_order_amount"
            />
            <PTextField
                v-model="rateForm.max_order_amount"
                label="Max order value"
                type="number"
                step="0.01"
                min="0"
                prefix="₹"
                :error="rateForm.errors.max_order_amount"
            />
            <PTextField
                v-model="rateForm.delivery_days_min"
                label="Delivery days (min)"
                type="number"
                min="0"
                :error="rateForm.errors.delivery_days_min"
            />
            <PTextField
                v-model="rateForm.delivery_days_max"
                label="Delivery days (max)"
                type="number"
                min="0"
                :error="rateForm.errors.delivery_days_max"
            />
            <div class="sm:col-span-2">
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
