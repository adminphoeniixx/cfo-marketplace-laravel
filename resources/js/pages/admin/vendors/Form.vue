<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';

type Vendor = {
    id: number;
    name: string;
    slug: string;
    store_email: string | null;
    phone: string | null;
    description: string | null;
    status: string;
    commission_type: string;
    commission_rate: string;
    contact_name: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    state: string | null;
    postcode: string | null;
    country: string;
    gst_number: string | null;
    payout_method: string;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_ifsc: string | null;
};

const props = defineProps<{ vendor: Vendor | null }>();

const isEdit = computed(() => !!props.vendor);

const form = useForm({
    name: props.vendor?.name ?? '',
    slug: props.vendor?.slug ?? '',
    store_email: props.vendor?.store_email ?? '',
    phone: props.vendor?.phone ?? '',
    description: props.vendor?.description ?? '',
    status: props.vendor?.status ?? 'pending',
    commission_type: props.vendor?.commission_type ?? 'percentage',
    commission_rate: props.vendor?.commission_rate ?? 10,
    contact_name: props.vendor?.contact_name ?? '',
    address_line1: props.vendor?.address_line1 ?? '',
    address_line2: props.vendor?.address_line2 ?? '',
    city: props.vendor?.city ?? '',
    state: props.vendor?.state ?? '',
    postcode: props.vendor?.postcode ?? '',
    country: props.vendor?.country ?? 'IN',
    gst_number: props.vendor?.gst_number ?? '',
    payout_method: props.vendor?.payout_method ?? 'bank',
    bank_account_name: props.vendor?.bank_account_name ?? '',
    bank_account_number: props.vendor?.bank_account_number ?? '',
    bank_ifsc: props.vendor?.bank_ifsc ?? '',
});

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/vendors/${props.vendor!.id}`);
    } else {
        form.post('/admin/vendors');
    }
};
</script>

<template>
    <Head :title="isEdit ? `Edit ${vendor!.name}` : 'New vendor'" />

    <form @submit.prevent="submit">
        <PageHeader
            :title="isEdit ? vendor!.name : 'Add vendor'"
            :back-href="
                isEdit ? `/admin/vendors/${vendor!.id}` : '/admin/vendors'
            "
        >
            <template #actions>
                <PButton href="/admin/vendors">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Save changes' : 'Create vendor' }}
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard title="Store details">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="form.name"
                            label="Store name"
                            required
                            :error="form.errors.name"
                        />
                        <PTextField
                            v-model="form.slug"
                            label="Store handle"
                            :error="form.errors.slug"
                            placeholder="auto-generated"
                        />
                        <PTextField
                            v-model="form.contact_name"
                            label="Contact person"
                            :error="form.errors.contact_name"
                        />
                        <PTextField
                            v-model="form.store_email"
                            label="Store email"
                            type="email"
                            :error="form.errors.store_email"
                        />
                        <PTextField
                            v-model="form.phone"
                            label="Phone"
                            :error="form.errors.phone"
                        />
                        <PTextField
                            v-model="form.gst_number"
                            label="GST number"
                            :error="form.errors.gst_number"
                        />
                        <div class="sm:col-span-2">
                            <PTextarea
                                v-model="form.description"
                                label="About the store"
                                :rows="4"
                                :error="form.errors.description"
                            />
                        </div>
                    </div>
                </PCard>

                <PCard title="Address">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <PTextField
                                v-model="form.address_line1"
                                label="Address line 1"
                                :error="form.errors.address_line1"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <PTextField
                                v-model="form.address_line2"
                                label="Address line 2"
                                :error="form.errors.address_line2"
                            />
                        </div>
                        <PTextField
                            v-model="form.city"
                            label="City"
                            :error="form.errors.city"
                        />
                        <PTextField
                            v-model="form.state"
                            label="State"
                            :error="form.errors.state"
                        />
                        <PTextField
                            v-model="form.postcode"
                            label="PIN code"
                            :error="form.errors.postcode"
                        />
                        <PTextField
                            v-model="form.country"
                            label="Country code"
                            required
                            :error="form.errors.country"
                            help-text="Two letters, e.g. IN"
                        />
                    </div>
                </PCard>

                <PCard title="Payout details">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PSelect
                            v-model="form.payout_method"
                            label="Payout method"
                            :options="[
                                { value: 'bank', label: 'Bank transfer' },
                                { value: 'upi', label: 'UPI' },
                                { value: 'paypal', label: 'PayPal' },
                                { value: 'manual', label: 'Manual / offline' },
                            ]"
                            :error="form.errors.payout_method"
                        />
                        <PTextField
                            v-model="form.bank_account_name"
                            label="Account holder name"
                            :error="form.errors.bank_account_name"
                        />
                        <PTextField
                            v-model="form.bank_account_number"
                            label="Account number / UPI ID"
                            :error="form.errors.bank_account_number"
                        />
                        <PTextField
                            v-model="form.bank_ifsc"
                            label="IFSC code"
                            :error="form.errors.bank_ifsc"
                        />
                    </div>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Status">
                    <PSelect
                        v-model="form.status"
                        :options="[
                            { value: 'pending', label: 'Pending approval' },
                            { value: 'approved', label: 'Approved' },
                            { value: 'suspended', label: 'Suspended' },
                            { value: 'rejected', label: 'Rejected' },
                        ]"
                        :error="form.errors.status"
                        help-text="Only approved vendors can sell."
                    />
                </PCard>

                <PCard title="Commission">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.commission_type"
                            label="Type"
                            :options="[
                                {
                                    value: 'percentage',
                                    label: 'Percentage of sale',
                                },
                                { value: 'flat', label: 'Flat fee per item' },
                            ]"
                            :error="form.errors.commission_type"
                        />
                        <PTextField
                            v-model="form.commission_rate"
                            label="Rate"
                            type="number"
                            step="0.01"
                            min="0"
                            :prefix="
                                form.commission_type === 'flat'
                                    ? '₹'
                                    : undefined
                            "
                            :suffix="
                                form.commission_type === 'percentage'
                                    ? '%'
                                    : undefined
                            "
                            required
                            :error="form.errors.commission_rate"
                        />
                        <p
                            class="rounded-lg bg-[#f1f1f1] p-2.5 text-xs text-[#616161] dark:bg-[#303030] dark:text-[#b5b5b5]"
                        >
                            On a ₹1,000 sale you would earn
                            <strong>
                                ₹{{
                                    form.commission_type === 'percentage'
                                        ? (
                                              (1000 *
                                                  Number(
                                                      form.commission_rate || 0,
                                                  )) /
                                              100
                                          ).toFixed(2)
                                        : Number(
                                              form.commission_rate || 0,
                                          ).toFixed(2)
                                }}
                            </strong>
                        </p>
                    </div>
                </PCard>
            </div>
        </div>
    </form>
</template>
