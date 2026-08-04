<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import {
    compactCurrency,
    currency,
    date,
    number,
    statusTone,
    titleCase,
} from '@/lib/format';

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
    rating: string;
    approved_at: string | null;
    rejection_reason: string | null;
    created_at: string;
};

const props = defineProps<{
    vendor: Vendor;
    stats: {
        revenue: number;
        commission: number;
        earning: number;
        units_sold: number;
        orders: number;
        products: number;
        active_products: number;
        paid_out: number;
    };
    products: {
        id: number;
        name: string;
        sku: string | null;
        price: string;
        stock_quantity: number;
        status: string;
        category: { name: string } | null;
    }[];
    recentOrders: {
        id: number;
        number: string;
        status: string;
        grand_total: string;
        placed_at: string;
        customer: { first_name: string; last_name: string } | null;
    }[];
    payouts: {
        id: number;
        number: string;
        period_start: string;
        period_end: string;
        net_amount: string;
        status: string;
    }[];
}>();

const showReject = ref(false);
const showPayout = ref(false);

const rejectForm = useForm({ status: 'rejected', rejection_reason: '' });
const payoutForm = useForm({
    vendor_id: props.vendor.id,
    period_start: new Date(Date.now() - 30 * 86400000)
        .toISOString()
        .slice(0, 10),
    period_end: new Date().toISOString().slice(0, 10),
    adjustment_amount: 0,
    note: '',
});

const setStatus = (status: string) =>
    router.patch(
        `/admin/vendors/${props.vendor.id}/status`,
        { status },
        { preserveScroll: true },
    );

const reject = () =>
    rejectForm.patch(`/admin/vendors/${props.vendor.id}/status`, {
        preserveScroll: true,
        onSuccess: () => (showReject.value = false),
    });

const generatePayout = () =>
    payoutForm.post('/admin/payouts', {
        preserveScroll: true,
        onSuccess: () => (showPayout.value = false),
    });

const pendingBalance = props.stats.earning - props.stats.paid_out;
</script>

<template>
    <Head :title="vendor.name" />

    <PageHeader
        :title="vendor.name"
        :subtitle="`Joined ${date(vendor.created_at)} · /${vendor.slug}`"
        back-href="/admin/vendors"
    >
        <template #badge>
            <PBadge :tone="statusTone(vendor.status)" dot>{{
                titleCase(vendor.status)
            }}</PBadge>
        </template>
        <template #actions>
            <template v-if="vendor.status === 'pending'">
                <PButton variant="critical" @click="showReject = true"
                    >Reject</PButton
                >
                <PButton variant="primary" @click="setStatus('approved')"
                    >Approve vendor</PButton
                >
            </template>
            <template v-else-if="vendor.status === 'approved'">
                <PButton @click="setStatus('suspended')">Suspend</PButton>
                <PButton variant="primary" @click="showPayout = true"
                    >Generate payout</PButton
                >
            </template>
            <PButton
                v-else-if="vendor.status === 'suspended'"
                variant="primary"
                @click="setStatus('approved')"
                >Reactivate</PButton
            >
            <PButton :href="`/admin/vendors/${vendor.id}/edit`">Edit</PButton>
        </template>
    </PageHeader>

    <div
        v-if="vendor.status === 'rejected' && vendor.rejection_reason"
        class="mb-4 rounded-xl border border-[#e51c00]/30 bg-[#ffd6d6] px-4 py-3 dark:bg-[#8e0b21]/20"
    >
        <p class="text-[13px] font-semibold text-[#8e0b21] dark:text-[#ffd6d6]">
            Application rejected
        </p>
        <p class="text-[13px] text-[#8e0b21] dark:text-[#ffd6d6]">
            {{ vendor.rejection_reason }}
        </p>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
            label="Gross sales"
            :value="compactCurrency(stats.revenue)"
            :caption="`${number(stats.orders)} orders`"
        />
        <MetricCard
            label="Commission taken"
            :value="compactCurrency(stats.commission)"
            caption="your revenue"
        />
        <MetricCard
            label="Vendor earnings"
            :value="compactCurrency(stats.earning)"
            caption="before payouts"
        />
        <MetricCard
            label="Outstanding balance"
            :value="compactCurrency(pendingBalance)"
            caption="yet to pay"
        />
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <PCard
                title="Products"
                :subtitle="`${stats.active_products} active of ${stats.products}`"
            >
                <template #actions>
                    <PButton
                        :href="`/admin/products?vendor=${vendor.id}`"
                        variant="plain"
                        size="slim"
                        >View all</PButton
                    >
                </template>

                <ul
                    v-if="products.length"
                    class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                >
                    <li
                        v-for="product in products"
                        :key="product.id"
                        class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/admin/products/${product.id}/edit`"
                                class="block truncate text-[13px] font-medium text-[#303030] hover:underline dark:text-[#e3e3e3]"
                            >
                                {{ product.name }}
                            </Link>
                            <p class="truncate text-xs text-[#8a8a8a]">
                                {{ product.sku || 'No SKU' }} ·
                                {{ product.category?.name ?? 'Uncategorised' }}
                            </p>
                        </div>
                        <PBadge :tone="statusTone(product.status)">{{
                            titleCase(product.status)
                        }}</PBadge>
                        <span
                            class="w-16 shrink-0 text-right text-xs text-[#8a8a8a]"
                            >{{ product.stock_quantity }} qty</span
                        >
                        <span
                            class="w-20 shrink-0 text-right text-[13px] font-semibold tabular-nums"
                            >{{ currency(product.price, 0) }}</span
                        >
                    </li>
                </ul>
                <PEmptyState
                    v-else
                    title="No products"
                    description="This vendor hasn't listed anything yet."
                />
            </PCard>

            <PCard title="Recent orders">
                <template #actions>
                    <PButton
                        :href="`/admin/orders?vendor=${vendor.id}`"
                        variant="plain"
                        size="slim"
                        >View all</PButton
                    >
                </template>

                <ul
                    v-if="recentOrders.length"
                    class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                >
                    <li
                        v-for="order in recentOrders"
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
                            <p class="truncate text-xs text-[#8a8a8a]">
                                {{
                                    order.customer
                                        ? `${order.customer.first_name} ${order.customer.last_name}`
                                        : 'Guest'
                                }}
                                ·
                                {{ date(order.placed_at) }}
                            </p>
                        </div>
                        <PBadge :tone="statusTone(order.status)" dot>{{
                            titleCase(order.status)
                        }}</PBadge>
                        <span
                            class="w-20 shrink-0 text-right text-[13px] font-semibold tabular-nums"
                            >{{ currency(order.grand_total, 0) }}</span
                        >
                    </li>
                </ul>
                <PEmptyState
                    v-else
                    title="No orders yet"
                    description="Orders containing this vendor's items will show here."
                />
            </PCard>

            <PCard title="Payout history">
                <template #actions>
                    <PButton
                        :href="`/admin/payouts?vendor=${vendor.id}`"
                        variant="plain"
                        size="slim"
                        >View all</PButton
                    >
                    <PButton size="slim" @click="showPayout = true"
                        >New payout</PButton
                    >
                </template>

                <ul
                    v-if="payouts.length"
                    class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]"
                >
                    <li
                        v-for="payout in payouts"
                        :key="payout.id"
                        class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{ payout.number }}
                            </p>
                            <p class="text-xs text-[#8a8a8a]">
                                {{ date(payout.period_start) }} –
                                {{ date(payout.period_end) }}
                            </p>
                        </div>
                        <PBadge :tone="statusTone(payout.status)" dot>{{
                            titleCase(payout.status)
                        }}</PBadge>
                        <span
                            class="w-24 shrink-0 text-right text-[13px] font-semibold tabular-nums"
                            >{{ currency(payout.net_amount) }}</span
                        >
                    </li>
                </ul>
                <PEmptyState
                    v-else
                    title="No payouts yet"
                    description="Generate a payout to settle this vendor's earnings."
                />
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard title="Store details">
                <dl class="space-y-2 text-[13px]">
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Contact
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.contact_name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Email
                        </dt>
                        <dd
                            class="break-all text-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ vendor.store_email ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Phone
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.phone ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            GST number
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.gst_number ?? '—' }}
                        </dd>
                    </div>
                    <div v-if="vendor.approved_at">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Approved
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ date(vendor.approved_at) }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard title="Commission">
                <p
                    class="text-2xl font-semibold text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{
                        vendor.commission_type === 'percentage'
                            ? `${vendor.commission_rate}%`
                            : currency(vendor.commission_rate)
                    }}
                </p>
                <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    {{
                        vendor.commission_type === 'percentage'
                            ? 'of each item sold'
                            : 'flat fee per item'
                    }}
                </p>
            </PCard>

            <PCard title="Address">
                <address
                    class="space-y-0.5 text-[13px] text-[#303030] not-italic dark:text-[#e3e3e3]"
                >
                    <p v-if="vendor.address_line1">
                        {{ vendor.address_line1 }}
                    </p>
                    <p v-if="vendor.address_line2">
                        {{ vendor.address_line2 }}
                    </p>
                    <p>
                        {{
                            [vendor.city, vendor.state, vendor.postcode]
                                .filter(Boolean)
                                .join(', ') || '—'
                        }}
                    </p>
                    <p>{{ vendor.country }}</p>
                </address>
            </PCard>

            <PCard title="Payout details">
                <dl class="space-y-2 text-[13px]">
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Method
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ titleCase(vendor.payout_method) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Account name
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.bank_account_name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Account number
                        </dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.bank_account_number ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">IFSC</dt>
                        <dd class="text-[#303030] dark:text-[#e3e3e3]">
                            {{ vendor.bank_ifsc ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard v-if="vendor.description" title="About">
                <p
                    class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ vendor.description }}
                </p>
            </PCard>
        </div>
    </div>

    <PModal
        :open="showReject"
        title="Reject vendor application"
        size="small"
        @close="showReject = false"
    >
        <PTextarea
            v-model="rejectForm.rejection_reason"
            label="Reason"
            :rows="4"
            :error="rejectForm.errors.rejection_reason"
        />
        <template #footer>
            <PButton @click="showReject = false">Cancel</PButton>
            <PButton
                variant="critical"
                :loading="rejectForm.processing"
                @click="reject"
                >Reject vendor</PButton
            >
        </template>
    </PModal>

    <PModal
        :open="showPayout"
        title="Generate payout"
        size="small"
        @close="showPayout = false"
    >
        <div class="space-y-3">
            <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                Sales in the selected period are totalled, commission deducted,
                and a payout record created.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <PTextField
                    v-model="payoutForm.period_start"
                    label="From"
                    type="date"
                    :error="payoutForm.errors.period_start"
                />
                <PTextField
                    v-model="payoutForm.period_end"
                    label="To"
                    type="date"
                    :error="payoutForm.errors.period_end"
                />
            </div>
            <PTextField
                v-model="payoutForm.adjustment_amount"
                label="Adjustment"
                type="number"
                step="0.01"
                prefix="₹"
                :error="payoutForm.errors.adjustment_amount"
                help-text="Negative to deduct."
            />
            <PTextarea
                v-model="payoutForm.note"
                label="Note"
                :rows="2"
                :error="payoutForm.errors.note"
            />
        </div>
        <template #footer>
            <PButton @click="showPayout = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="payoutForm.processing"
                @click="generatePayout"
                >Generate</PButton
            >
        </template>
    </PModal>
</template>
