<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PModal from '@/components/admin/PModal.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { number } from '@/lib/format';

type DeliveryPartner = {
    id: number;
    name: string;
    code: string;
    tracking_url: string | null;
    support_phone: string | null;
    notes: string | null;
    is_active: boolean;
    position: number;
    orders_count: number;
};

const props = defineProps<{
    settings: Record<string, string>;
    deliveryPartners: DeliveryPartner[];
    unlistedPartners: string[];
}>();

const form = useForm({
    store_name: props.settings.store_name ?? '',
    store_email: props.settings.store_email ?? '',
    store_phone: props.settings.store_phone ?? '',
    currency: props.settings.currency ?? 'INR',
    weight_unit: props.settings.weight_unit ?? 'kg',
    order_prefix: props.settings.order_prefix ?? '#',
    default_commission: props.settings.default_commission ?? '10',
    auto_approve_vendors: props.settings.auto_approve_vendors === '1',
    auto_approve_cancellations:
        props.settings.auto_approve_cancellations === '1',
    low_stock_threshold: props.settings.low_stock_threshold ?? '5',
    address: props.settings.address ?? '',
});

const submit = () => form.put('/admin/settings', { preserveScroll: true });

/* ------------------------------------------------------ delivery partners */
const showPartner = ref(false);
const editingPartner = ref<DeliveryPartner | null>(null);

const partnerForm = useForm({
    name: '',
    tracking_url: '',
    support_phone: '',
    notes: '',
    is_active: true,
    position: 0,
});

const openPartner = (partner: DeliveryPartner | null) => {
    editingPartner.value = partner;
    partnerForm.clearErrors();

    Object.assign(partnerForm, {
        name: partner?.name ?? '',
        tracking_url: partner?.tracking_url ?? '',
        support_phone: partner?.support_phone ?? '',
        notes: partner?.notes ?? '',
        is_active: partner?.is_active ?? true,
        position: partner?.position ?? 0,
    });

    showPartner.value = true;
};

const submitPartner = () => {
    const onSuccess = () => (showPartner.value = false);

    if (editingPartner.value) {
        partnerForm.put(
            `/admin/settings/delivery-partners/${editingPartner.value.id}`,
            { preserveScroll: true, onSuccess },
        );

        return;
    }

    partnerForm.post('/admin/settings/delivery-partners', {
        preserveScroll: true,
        onSuccess,
    });
};

const togglePartner = (partner: DeliveryPartner) =>
    router.patch(
        `/admin/settings/delivery-partners/${partner.id}/toggle`,
        {},
        { preserveScroll: true },
    );

const destroyPartner = (partner: DeliveryPartner) =>
    router.delete(`/admin/settings/delivery-partners/${partner.id}`, {
        preserveScroll: true,
    });
</script>

<template>
    <Head title="Store settings" />

    <form @submit.prevent="submit">
        <PageHeader
            title="Store settings"
            subtitle="Defaults used across the admin and storefront"
        >
            <template #actions>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                    >Save settings</PButton
                >
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard title="Store profile">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="form.store_name"
                            label="Store name"
                            required
                            :error="form.errors.store_name"
                        />
                        <PTextField
                            v-model="form.store_email"
                            label="Support email"
                            type="email"
                            required
                            :error="form.errors.store_email"
                        />
                        <PTextField
                            v-model="form.store_phone"
                            label="Support phone"
                            :error="form.errors.store_phone"
                        />
                        <PTextField
                            v-model="form.order_prefix"
                            label="Order number prefix"
                            required
                            :error="form.errors.order_prefix"
                        />
                        <div class="sm:col-span-2">
                            <PTextarea
                                v-model="form.address"
                                label="Business address"
                                :rows="3"
                                :error="form.errors.address"
                            />
                        </div>
                    </div>
                </PCard>

                <PCard title="Regional">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PSelect
                            v-model="form.currency"
                            label="Currency"
                            :options="[
                                { value: 'INR', label: 'Indian Rupee (₹)' },
                                { value: 'USD', label: 'US Dollar ($)' },
                                { value: 'EUR', label: 'Euro (€)' },
                                { value: 'GBP', label: 'Pound Sterling (£)' },
                            ]"
                            :error="form.errors.currency"
                        />
                        <PSelect
                            v-model="form.weight_unit"
                            label="Weight unit"
                            :options="[
                                { value: 'kg', label: 'Kilograms (kg)' },
                                { value: 'g', label: 'Grams (g)' },
                                { value: 'lb', label: 'Pounds (lb)' },
                            ]"
                            :error="form.errors.weight_unit"
                        />
                    </div>
                </PCard>

                <PCard title="Store defaults">
                    <div class="space-y-3">
                        <PTextField
                            v-model="form.default_commission"
                            label="Default commission"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            suffix="%"
                            required
                            :error="form.errors.default_commission"
                            help-text="Applied to new vendors unless overridden."
                        />
                        <PCheckbox
                            v-model="form.auto_approve_vendors"
                            label="Auto-approve new vendor applications"
                            help-text="Skip manual review when a vendor signs up."
                        />
                    </div>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Inventory">
                    <PTextField
                        v-model="form.low_stock_threshold"
                        label="Low stock threshold"
                        type="number"
                        min="0"
                        required
                        :error="form.errors.low_stock_threshold"
                        help-text="Default alert level for new products."
                    />
                </PCard>

                <PCard
                    title="Delivery partners"
                    subtitle="Couriers staff can pick when fulfilling an order"
                >
                    <template #actions>
                        <PButton size="slim" @click="openPartner(null)">
                            Add partner
                        </PButton>
                    </template>

                    <p
                        v-if="unlistedPartners.length"
                        class="mb-3 text-xs text-[#8a8a8a]"
                    >
                        Also on past orders, but not in this list:
                        {{ unlistedPartners.join(', ') }}
                    </p>

                    <ul
                        v-if="deliveryPartners.length"
                        class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                    >
                        <li
                            v-for="partner in deliveryPartners"
                            :key="partner.id"
                            class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="flex items-center gap-2 text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                >
                                    {{ partner.name }}
                                    <PBadge
                                        v-if="!partner.is_active"
                                        tone="neutral"
                                        >Hidden</PBadge
                                    >
                                </p>
                                <p class="truncate text-xs text-[#8a8a8a]">
                                    {{
                                        partner.tracking_url
                                            ? 'Tracking link configured'
                                            : 'No tracking link'
                                    }}
                                    · {{ number(partner.orders_count) }}
                                    shipment(s)
                                    <template v-if="partner.support_phone">
                                        · {{ partner.support_phone }}
                                    </template>
                                </p>
                            </div>
                            <div class="flex gap-1.5">
                                <PButton
                                    size="slim"
                                    @click="openPartner(partner)"
                                    >Edit</PButton
                                >
                                <PButton
                                    size="slim"
                                    @click="togglePartner(partner)"
                                >
                                    {{
                                        partner.is_active ? 'Hide' : 'Activate'
                                    }}
                                </PButton>
                                <PButton
                                    size="slim"
                                    variant="critical"
                                    :disabled="partner.orders_count > 0"
                                    @click="destroyPartner(partner)"
                                    >Delete</PButton
                                >
                            </div>
                        </li>
                    </ul>

                    <p v-else class="text-[13px] text-[#8a8a8a]">
                        No delivery partners yet.
                    </p>
                </PCard>

                <PCard title="Order handling">
                    <PCheckbox
                        v-model="form.auto_approve_cancellations"
                        label="Auto-approve cancellations"
                        help-text="Immediately approve customer cancellation requests on unfulfilled orders."
                    />
                </PCard>

                <PCard title="Related">
                    <div class="space-y-1.5">
                        <PButton href="/admin/taxes" size="slim" full-width
                            >Tax settings</PButton
                        >
                        <PButton href="/admin/shipping" size="slim" full-width
                            >Shipping settings</PButton
                        >
                        <PButton href="/settings/profile" size="slim" full-width
                            >Your profile</PButton
                        >
                    </div>
                </PCard>
            </div>
        </div>
    </form>

    <PModal
        :open="showPartner"
        :title="
            editingPartner ? 'Edit delivery partner' : 'Add delivery partner'
        "
        size="small"
        @close="showPartner = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="partnerForm.name"
                label="Name"
                required
                placeholder="Delhivery, Blue Dart…"
                :error="partnerForm.errors.name"
                help-text="Renaming updates past orders shipped with this partner."
            />
            <PTextField
                v-model="partnerForm.tracking_url"
                label="Tracking URL"
                placeholder="https://courier.com/track/{tracking}"
                :error="partnerForm.errors.tracking_url"
                help-text="Use {tracking} where the tracking number goes. Leave blank for none."
            />
            <PTextField
                v-model="partnerForm.support_phone"
                label="Support phone"
                :error="partnerForm.errors.support_phone"
            />
            <PTextarea
                v-model="partnerForm.notes"
                label="Notes"
                :rows="2"
                :error="partnerForm.errors.notes"
                placeholder="Pickup cut-off, serviceable pin codes…"
            />
            <PTextField
                v-model="partnerForm.position"
                label="Sort order"
                type="number"
                min="0"
                :error="partnerForm.errors.position"
            />
            <PCheckbox
                v-model="partnerForm.is_active"
                label="Available when fulfilling orders"
                help-text="Hidden partners stay on past orders but cannot be picked."
            />
        </div>

        <template #footer>
            <PButton @click="showPartner = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="partnerForm.processing"
                @click="submitPartner"
            >
                {{ editingPartner ? 'Save' : 'Add partner' }}
            </PButton>
        </template>
    </PModal>
</template>
