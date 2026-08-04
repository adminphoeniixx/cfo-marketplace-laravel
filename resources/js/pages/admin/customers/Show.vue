<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PTextField from '@/components/admin/PTextField.vue';
import {
    compactCurrency,
    currency,
    date,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

type Address = {
    id: number;
    label: string;
    first_name: string;
    last_name: string | null;
    company: string | null;
    address_line1: string;
    address_line2: string | null;
    city: string;
    state: string | null;
    postcode: string | null;
    country: string;
    phone: string | null;
    is_default_billing: boolean;
    is_default_shipping: boolean;
};

type Customer = {
    id: number;
    first_name: string;
    last_name: string | null;
    email: string;
    phone: string | null;
    status: string;
    accepts_marketing: boolean;
    notes: string | null;
    tags: string[] | null;
    total_spent: string;
    orders_count: number;
    last_order_at: string | null;
    created_at: string;
    addresses: Address[];
    orders: {
        id: number;
        number: string;
        status: string;
        payment_status: string;
        grand_total: string;
        placed_at: string;
    }[];
};

const props = defineProps<{
    customer: Customer;
    stats: {
        lifetime_value: number;
        orders_count: number;
        average_order: number;
        refunded: number;
        cancelled: number;
    };
}>();

const showAddress = ref(false);
const editing = ref<Address | null>(null);

const blankAddress = {
    label: 'Home',
    first_name: props.customer.first_name,
    last_name: props.customer.last_name ?? '',
    company: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postcode: '',
    country: 'IN',
    phone: props.customer.phone ?? '',
    is_default_billing: false,
    is_default_shipping: false,
};

const addressForm = useForm({ ...blankAddress });

const openAddress = (address: Address | null) => {
    editing.value = address;

    if (address) {
        // The API returns nullable columns as null; the form fields are strings,
        // so normalise once and use it for both the defaults and the values —
        // otherwise a reset() would put nulls back into the inputs.
        const values = {
            ...address,
            last_name: address.last_name ?? '',
            company: address.company ?? '',
            address_line2: address.address_line2 ?? '',
            state: address.state ?? '',
            postcode: address.postcode ?? '',
            phone: address.phone ?? '',
        };

        addressForm.defaults(values);
        Object.assign(addressForm, values);
    } else {
        Object.assign(addressForm, { ...blankAddress });
    }

    addressForm.clearErrors();
    showAddress.value = true;
};

const submitAddress = () => {
    const onSuccess = () => (showAddress.value = false);

    if (editing.value) {
        addressForm.put(
            `/admin/customers/${props.customer.id}/addresses/${editing.value.id}`,
            { preserveScroll: true, onSuccess },
        );
    } else {
        addressForm.post(`/admin/customers/${props.customer.id}/addresses`, {
            preserveScroll: true,
            onSuccess,
        });
    }
};

const deleteAddress = (address: Address) =>
    router.delete(
        `/admin/customers/${props.customer.id}/addresses/${address.id}`,
        { preserveScroll: true },
    );

const toggleStatus = () =>
    router.patch(
        `/admin/customers/${props.customer.id}/toggle-status`,
        {},
        { preserveScroll: true },
    );
</script>

<template>
    <Head :title="`${customer.first_name} ${customer.last_name ?? ''}`" />

    <PageHeader
        :title="`${customer.first_name} ${customer.last_name ?? ''}`"
        :subtitle="`Customer since ${date(customer.created_at)}`"
        back-href="/admin/customers"
    >
        <template #badge>
            <PBadge
                :tone="customer.status === 'active' ? 'success' : 'critical'"
                dot
                >{{ titleCase(customer.status) }}</PBadge
            >
            <PBadge v-if="customer.accepts_marketing" tone="new"
                >Subscribed</PBadge
            >
        </template>
        <template #actions>
            <PButton @click="toggleStatus">{{
                customer.status === 'active' ? 'Block customer' : 'Unblock'
            }}</PButton>
            <PButton
                :href="`/admin/customers/${customer.id}/edit`"
                variant="primary"
                >Edit</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <MetricCard
            label="Lifetime value"
            :value="compactCurrency(stats.lifetime_value)"
        />
        <MetricCard label="Orders" :value="number(stats.orders_count)" />
        <MetricCard
            label="Avg. order"
            :value="compactCurrency(stats.average_order)"
        />
        <MetricCard label="Refunded" :value="compactCurrency(stats.refunded)" />
        <MetricCard
            label="Cancelled"
            :value="number(stats.cancelled)"
            caption="orders"
        />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <PCard
                title="Order history"
                :subtitle="`Last ${customer.orders.length} order(s)`"
            >
                <template #actions>
                    <PButton
                        :href="`/admin/orders?search=${customer.email}`"
                        variant="plain"
                        size="slim"
                        >View all</PButton
                    >
                </template>

                <ul
                    v-if="customer.orders.length"
                    class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                >
                    <li
                        v-for="order in customer.orders"
                        :key="order.id"
                        class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/admin/orders/${order.id}`"
                                class="text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ order.number }}
                            </Link>
                            <p class="text-xs text-[#8a8a8a]">
                                {{ date(order.placed_at, true) }}
                            </p>
                        </div>
                        <PBadge :tone="statusTone(order.payment_status)">{{
                            titleCase(order.payment_status)
                        }}</PBadge>
                        <PBadge :tone="statusTone(order.status)" dot>{{
                            titleCase(order.status)
                        }}</PBadge>
                        <span
                            class="w-24 shrink-0 text-right text-[13px] font-semibold tabular-nums"
                            >{{ currency(order.grand_total) }}</span
                        >
                    </li>
                </ul>
                <PEmptyState
                    v-else
                    title="No orders yet"
                    description="This customer hasn't placed an order."
                />
            </PCard>

            <PCard
                title="Addresses"
                :subtitle="`${customer.addresses.length} saved`"
            >
                <template #actions>
                    <PButton size="slim" @click="openAddress(null)"
                        >Add address</PButton
                    >
                </template>

                <div
                    v-if="customer.addresses.length"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div
                        v-for="address in customer.addresses"
                        :key="address.id"
                        class="rounded-lg border border-[#e3e3e3] p-3 dark:border-[#3a3a3a]"
                    >
                        <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                            <span
                                class="text-[13px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
                                >{{ address.label }}</span
                            >
                            <PBadge
                                v-if="address.is_default_shipping"
                                tone="info"
                                >Default shipping</PBadge
                            >
                            <PBadge v-if="address.is_default_billing" tone="new"
                                >Default billing</PBadge
                            >
                        </div>
                        <address
                            class="space-y-0.5 text-[13px] text-[#616161] not-italic dark:text-[#b5b5b5]"
                        >
                            <p>
                                {{ address.first_name }} {{ address.last_name }}
                            </p>
                            <p v-if="address.company">{{ address.company }}</p>
                            <p>{{ address.address_line1 }}</p>
                            <p v-if="address.address_line2">
                                {{ address.address_line2 }}
                            </p>
                            <p>
                                {{
                                    [
                                        address.city,
                                        address.state,
                                        address.postcode,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')
                                }}
                            </p>
                            <p>{{ address.country }}</p>
                            <p v-if="address.phone">{{ address.phone }}</p>
                        </address>
                        <div class="mt-2 flex gap-1">
                            <PButton size="micro" @click="openAddress(address)"
                                >Edit</PButton
                            >
                            <PButton
                                size="micro"
                                variant="plain"
                                @click="deleteAddress(address)"
                                >Remove</PButton
                            >
                        </div>
                    </div>
                </div>
                <PEmptyState
                    v-else
                    title="No addresses"
                    description="Add a shipping or billing address for faster checkout."
                />
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard title="Contact">
                <dl class="space-y-2 text-[13px]">
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Email
                        </dt>
                        <dd
                            class="break-all text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ customer.email }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Phone
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ customer.phone ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Last order
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{
                                customer.last_order_at
                                    ? date(customer.last_order_at)
                                    : '—'
                            }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard v-if="customer.tags?.length" title="Tags">
                <div class="flex flex-wrap gap-1">
                    <PBadge
                        v-for="tag in customer.tags"
                        :key="tag"
                        tone="new"
                        >{{ tag }}</PBadge
                    >
                </div>
            </PCard>

            <PCard title="Internal notes">
                <p
                    class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ customer.notes || 'No notes yet.' }}
                </p>
            </PCard>
        </div>
    </div>

    <PModal
        :open="showAddress"
        :title="editing ? 'Edit address' : 'Add address'"
        size="large"
        @close="showAddress = false"
    >
        <div class="grid gap-3 sm:grid-cols-2">
            <PTextField
                v-model="addressForm.label"
                label="Label"
                :error="addressForm.errors.label"
            />
            <PTextField
                v-model="addressForm.phone"
                label="Phone"
                :error="addressForm.errors.phone"
            />
            <PTextField
                v-model="addressForm.first_name"
                label="First name"
                required
                :error="addressForm.errors.first_name"
            />
            <PTextField
                v-model="addressForm.last_name"
                label="Last name"
                :error="addressForm.errors.last_name"
            />
            <div class="sm:col-span-2">
                <PTextField
                    v-model="addressForm.company"
                    label="Company"
                    :error="addressForm.errors.company"
                />
            </div>
            <div class="sm:col-span-2">
                <PTextField
                    v-model="addressForm.address_line1"
                    label="Address line 1"
                    required
                    :error="addressForm.errors.address_line1"
                />
            </div>
            <div class="sm:col-span-2">
                <PTextField
                    v-model="addressForm.address_line2"
                    label="Address line 2"
                    :error="addressForm.errors.address_line2"
                />
            </div>
            <PTextField
                v-model="addressForm.city"
                label="City"
                required
                :error="addressForm.errors.city"
            />
            <PTextField
                v-model="addressForm.state"
                label="State"
                :error="addressForm.errors.state"
            />
            <PTextField
                v-model="addressForm.postcode"
                label="PIN code"
                :error="addressForm.errors.postcode"
            />
            <PTextField
                v-model="addressForm.country"
                label="Country code"
                :error="addressForm.errors.country"
                help-text="Two-letter code, e.g. IN"
            />
            <PCheckbox
                v-model="addressForm.is_default_shipping"
                label="Default shipping address"
            />
            <PCheckbox
                v-model="addressForm.is_default_billing"
                label="Default billing address"
            />
        </div>
        <template #footer>
            <PButton @click="showAddress = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="addressForm.processing"
                @click="submitAddress"
            >
                {{ editing ? 'Save address' : 'Add address' }}
            </PButton>
        </template>
    </PModal>
</template>
