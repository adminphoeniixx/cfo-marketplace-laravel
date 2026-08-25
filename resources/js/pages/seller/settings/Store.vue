<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { date, statusTone, titleCase } from '@/lib/format';

const props = defineProps<{
    store: {
        id: number;
        name: string;
        slug: string;
        store_email: string | null;
        phone: string | null;
        description: string | null;
        logo_path: string | null;
        contact_name: string | null;
        address_line1: string | null;
        address_line2: string | null;
        city: string | null;
        state: string | null;
        postcode: string | null;
        country: string | null;
        gst_number: string | null;
        payout_method: string | null;
        bank_account_name: string | null;
        bank_account_number: string | null;
        bank_ifsc: string | null;
    };
    marketplace: {
        status: string;
        commission_type: string;
        commission_rate: string;
        rating: string | null;
        approved_at: string | null;
    };
    payoutMethods: string[];
}>();

const form = useForm({
    name: props.store.name ?? '',
    store_email: props.store.store_email ?? '',
    phone: props.store.phone ?? '',
    description: props.store.description ?? '',
    logo_path: props.store.logo_path ?? '',
    contact_name: props.store.contact_name ?? '',
    address_line1: props.store.address_line1 ?? '',
    address_line2: props.store.address_line2 ?? '',
    city: props.store.city ?? '',
    state: props.store.state ?? '',
    postcode: props.store.postcode ?? '',
    country: props.store.country ?? 'IN',
    gst_number: props.store.gst_number ?? '',
    payout_method: props.store.payout_method ?? '',
    bank_account_name: props.store.bank_account_name ?? '',
    bank_account_number: props.store.bank_account_number ?? '',
    bank_ifsc: props.store.bank_ifsc ?? '',
});

const payoutOptions = computed(() => [
    { value: '', label: 'Not set' },
    ...props.payoutMethods.map((method) => ({
        value: method,
        label: titleCase(method),
    })),
]);

/* --------------------------------------------------------------- logo */
const logoInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadError = ref('');
const logoPreview = ref<string | null>(null);

const uploadLogo = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    try {
        const body = new FormData();
        body.append('file', file);
        body.append('folder', 'vendors');

        const response = await fetch('/seller/uploads', {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));

            throw new Error(
                payload.message ?? `Upload failed (${response.status})`,
            );
        }

        const { path, url } = await response.json();

        form.logo_path = path;
        logoPreview.value = url;
    } catch (error) {
        uploadError.value =
            error instanceof Error ? error.message : 'Upload failed.';
    } finally {
        uploading.value = false;
        input.value = '';
    }
};

const submit = () =>
    form.put('/seller/settings/store', { preserveScroll: true });
</script>

<template>
    <Head title="Store settings" />

    <form @submit.prevent="submit">
        <PageHeader
            title="Store settings"
            subtitle="Your shopfront and where your payouts land."
        >
            <template #actions>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    Save
                </PButton>
            </template>
        </PageHeader>

        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="min-w-0 space-y-4">
                <PCard title="Shopfront">
                    <div class="space-y-4">
                        <PTextField
                            v-model="form.name"
                            label="Store name"
                            :error="form.errors.name"
                            required
                        />
                        <PTextarea
                            v-model="form.description"
                            label="About your store"
                            :rows="4"
                            :error="form.errors.description"
                        />

                        <div>
                            <p class="mb-1 text-[13px] font-medium">Logo</p>
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="logoPreview || store.logo_path"
                                    :src="logoPreview ?? store.logo_path!"
                                    alt="Store logo"
                                    class="size-14 rounded-lg object-cover"
                                />
                                <span
                                    v-else
                                    class="size-14 rounded-lg bg-[#f1f1f1] dark:bg-[#303030]"
                                />
                                <input
                                    ref="logoInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="uploadLogo"
                                />
                                <PButton
                                    size="slim"
                                    :loading="uploading"
                                    :disabled="uploading"
                                    @click="logoInput?.click()"
                                >
                                    Upload logo
                                </PButton>
                            </div>
                            <p
                                v-if="uploadError"
                                class="mt-1 text-xs font-medium text-[#e51c00]"
                            >
                                {{ uploadError }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
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
                        </div>
                        <PTextField
                            v-model="form.contact_name"
                            label="Contact person"
                            :error="form.errors.contact_name"
                        />
                    </div>
                </PCard>

                <PCard title="Pickup address">
                    <div class="space-y-4">
                        <PTextField
                            v-model="form.address_line1"
                            label="Address line 1"
                            :error="form.errors.address_line1"
                        />
                        <PTextField
                            v-model="form.address_line2"
                            label="Address line 2"
                            :error="form.errors.address_line2"
                        />
                        <div class="grid gap-4 sm:grid-cols-3">
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
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <PTextField
                                v-model="form.country"
                                label="Country"
                                help-text="Two-letter code, e.g. IN"
                                :error="form.errors.country"
                            />
                            <PTextField
                                v-model="form.gst_number"
                                label="GST number"
                                :error="form.errors.gst_number"
                            />
                        </div>
                    </div>
                </PCard>

                <PCard
                    title="Payout account"
                    subtitle="Where the marketplace sends your earnings."
                >
                    <div class="space-y-4">
                        <PSelect
                            v-model="form.payout_method"
                            label="Payout method"
                            :options="payoutOptions"
                            :error="form.errors.payout_method"
                        />
                        <PTextField
                            v-model="form.bank_account_name"
                            label="Account holder name"
                            :error="form.errors.bank_account_name"
                        />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <PTextField
                                v-model="form.bank_account_number"
                                label="Account number"
                                :error="form.errors.bank_account_number"
                            />
                            <PTextField
                                v-model="form.bank_ifsc"
                                label="IFSC"
                                :error="form.errors.bank_ifsc"
                            />
                        </div>
                    </div>
                </PCard>
            </div>

            <!-- Set by the marketplace, read-only here. -->
            <div class="space-y-4">
                <PCard title="Set by the marketplace">
                    <dl class="space-y-2 text-[13px]">
                        <div class="flex items-center justify-between">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Status
                            </dt>
                            <dd>
                                <PBadge
                                    :tone="statusTone(marketplace.status)"
                                    dot
                                >
                                    {{ titleCase(marketplace.status) }}
                                </PBadge>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Commission
                            </dt>
                            <dd>
                                {{ marketplace.commission_rate
                                }}{{
                                    marketplace.commission_type === 'percentage'
                                        ? '%'
                                        : ''
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="marketplace.rating"
                            class="flex justify-between"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Rating
                            </dt>
                            <dd>{{ marketplace.rating }}</dd>
                        </div>
                        <div
                            v-if="marketplace.approved_at"
                            class="flex justify-between"
                        >
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Approved
                            </dt>
                            <dd>{{ date(marketplace.approved_at) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-[#8a8a8a]">
                        These are the marketplace's to set. Contact support if
                        something looks wrong.
                    </p>
                </PCard>

                <PCard title="Store URL">
                    <p class="font-mono text-[13px] break-all">
                        /store/{{ store.slug }}
                    </p>
                </PCard>
            </div>
        </div>
    </form>
</template>
