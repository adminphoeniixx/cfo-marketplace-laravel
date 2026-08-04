<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';

const props = defineProps<{ settings: Record<string, string> }>();

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
</template>
