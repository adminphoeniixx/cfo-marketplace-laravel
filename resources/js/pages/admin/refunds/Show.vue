<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PModal from '@/components/admin/PModal.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { currency, date, statusTone, titleCase } from '@/lib/format';

type Refund = {
    id: number;
    number: string;
    type: string;
    reason: string;
    note: string | null;
    status: string;
    items_amount: string;
    shipping_amount: string;
    tax_amount: string;
    adjustment_amount: string;
    total_amount: string;
    method: string;
    transaction_reference: string | null;
    restock: boolean;
    review_note: string | null;
    reviewed_at: string | null;
    processed_at: string | null;
    created_at: string;
    order: {
        id: number;
        number: string;
        grand_total: string;
        refunded_total: string;
        payment_status: string;
    };
    customer: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    } | null;
    reviewer: { name: string } | null;
    cancellation: { id: number; number: string } | null;
    items: {
        id: number;
        quantity: number;
        amount: string;
        order_item: {
            id: number;
            name: string;
            sku: string | null;
            unit_price: string;
            quantity: number;
        };
    }[];
};

const props = defineProps<{
    refund: Refund;
    reasons: Record<string, string>;
    methods: Record<string, string>;
}>();

const showApprove = ref(false);
const showReject = ref(false);
const showProcess = ref(false);

const approveForm = useForm({ review_note: '' });
const rejectForm = useForm({ review_note: '' });
const processForm = useForm({ transaction_reference: '' });

const approve = () =>
    approveForm.patch(`/admin/refunds/${props.refund.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => (showApprove.value = false),
    });

const reject = () =>
    rejectForm.patch(`/admin/refunds/${props.refund.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => (showReject.value = false),
    });

const process = () =>
    processForm.patch(`/admin/refunds/${props.refund.id}/process`, {
        preserveScroll: true,
        onSuccess: () => (showProcess.value = false),
    });
</script>

<template>
    <Head :title="`Refund ${refund.number}`" />

    <PageHeader
        :title="refund.number"
        :subtitle="`Raised ${date(refund.created_at, true)}`"
        back-href="/admin/refunds"
    >
        <template #badge>
            <PBadge :tone="statusTone(refund.status)" dot>{{
                titleCase(refund.status)
            }}</PBadge>
            <PBadge tone="new">{{ titleCase(refund.type) }} refund</PBadge>
        </template>
        <template #actions>
            <template v-if="refund.status === 'pending'">
                <PButton variant="critical" @click="showReject = true"
                    >Reject</PButton
                >
                <PButton variant="primary" @click="showApprove = true"
                    >Approve</PButton
                >
            </template>
            <template v-else-if="refund.status === 'approved'">
                <PButton variant="critical" @click="showReject = true"
                    >Reject</PButton
                >
                <PButton variant="primary" @click="showProcess = true"
                    >Process refund</PButton
                >
            </template>
        </template>
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <PCard v-if="refund.items.length" title="Items being refunded">
                <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                    <li
                        v-for="line in refund.items"
                        :key="line.id"
                        class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{ line.order_item.name }}
                            </p>
                            <p class="text-xs text-[#8a8a8a]">
                                {{ line.order_item.sku || 'No SKU' }} ·
                                {{ currency(line.order_item.unit_price) }} each
                                · qty {{ line.quantity }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-[13px] font-semibold tabular-nums"
                            >{{ currency(line.amount) }}</span
                        >
                    </li>
                </ul>
            </PCard>

            <PCard title="Refund breakdown">
                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Items
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.items_amount) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Shipping
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.shipping_amount) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Tax included
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.tax_amount) }}
                        </dd>
                    </div>
                    <div
                        v-if="Number(refund.adjustment_amount) !== 0"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Adjustment
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.adjustment_amount) }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between gap-3 border-t border-[#e3e3e3] pt-2 dark:border-[#3a3a3a]"
                    >
                        <dt class="font-semibold">Total refund</dt>
                        <dd class="text-base font-semibold tabular-nums">
                            {{ currency(refund.total_amount) }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard title="Reason">
                <p
                    class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ reasons[refund.reason] ?? titleCase(refund.reason) }}
                </p>
                <p
                    v-if="refund.note"
                    class="mt-1.5 text-[13px] whitespace-pre-line text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ refund.note }}
                </p>
            </PCard>

            <PCard v-if="refund.review_note" title="Review decision">
                <p
                    class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ refund.review_note }}
                </p>
                <p class="mt-1.5 text-xs text-[#8a8a8a]">
                    {{ titleCase(refund.status) }}
                    <span v-if="refund.reviewer">
                        by {{ refund.reviewer.name }}</span
                    >
                    <span v-if="refund.reviewed_at">
                        · {{ date(refund.reviewed_at, true) }}</span
                    >
                </p>
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard title="Order">
                <Link
                    :href="`/admin/orders/${refund.order.id}`"
                    class="text-[13px] font-semibold text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                >
                    {{ refund.order.number }}
                </Link>
                <dl class="mt-2 space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Order total
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.order.grand_total) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Already refunded
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(refund.order.refunded_total) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Payment
                        </dt>
                        <dd>
                            <PBadge
                                :tone="statusTone(refund.order.payment_status)"
                                >{{
                                    titleCase(refund.order.payment_status)
                                }}</PBadge
                            >
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard title="Customer">
                <div v-if="refund.customer">
                    <Link
                        :href="`/admin/customers/${refund.customer.id}`"
                        class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ refund.customer.first_name }}
                        {{ refund.customer.last_name }}
                    </Link>
                    <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                        {{ refund.customer.email }}
                    </p>
                </div>
                <p v-else class="text-[13px] text-[#8a8a8a]">Guest order</p>
            </PCard>

            <PCard title="Payment">
                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Method
                        </dt>
                        <dd class="text-right">
                            {{
                                methods[refund.method] ??
                                titleCase(refund.method)
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Reference
                        </dt>
                        <dd class="truncate text-right">
                            {{ refund.transaction_reference ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Restock
                        </dt>
                        <dd>
                            <PBadge
                                :tone="refund.restock ? 'success' : 'neutral'"
                                >{{ refund.restock ? 'Yes' : 'No' }}</PBadge
                            >
                        </dd>
                    </div>
                    <div
                        v-if="refund.processed_at"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Processed
                        </dt>
                        <dd class="text-right">
                            {{ date(refund.processed_at, true) }}
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard v-if="refund.cancellation" title="Linked cancellation">
                <Link
                    :href="`/admin/cancellations/${refund.cancellation.id}`"
                    class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                >
                    {{ refund.cancellation.number }}
                </Link>
            </PCard>
        </div>
    </div>

    <PModal
        :open="showApprove"
        title="Approve refund"
        size="small"
        @close="showApprove = false"
    >
        <div class="space-y-3">
            <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                Approve a refund of
                <strong>{{ currency(refund.total_amount) }}</strong
                >? Money only moves once you process it.
            </p>
            <PTextarea
                v-model="approveForm.review_note"
                label="Internal note (optional)"
                :rows="3"
                :error="approveForm.errors.review_note"
            />
        </div>
        <template #footer>
            <PButton @click="showApprove = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="approveForm.processing"
                @click="approve"
                >Approve</PButton
            >
        </template>
    </PModal>

    <PModal
        :open="showReject"
        title="Reject refund"
        size="small"
        @close="showReject = false"
    >
        <PTextarea
            v-model="rejectForm.review_note"
            label="Reason for rejection"
            :rows="4"
            :error="rejectForm.errors.review_note"
        />
        <template #footer>
            <PButton @click="showReject = false">Cancel</PButton>
            <PButton
                variant="critical"
                :loading="rejectForm.processing"
                @click="reject"
                >Reject refund</PButton
            >
        </template>
    </PModal>

    <PModal
        :open="showProcess"
        title="Process refund"
        size="small"
        @close="showProcess = false"
    >
        <div class="space-y-3">
            <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                This marks
                <strong>{{ currency(refund.total_amount) }}</strong> as
                refunded, updates the order totals
                <span v-if="refund.restock">and returns the items to stock</span
                >.
            </p>
            <PTextField
                v-model="processForm.transaction_reference"
                label="Transaction reference"
                placeholder="e.g. gateway refund id"
                :error="processForm.errors.transaction_reference"
            />
        </div>
        <template #footer>
            <PButton @click="showProcess = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="processForm.processing"
                @click="process"
                >Process refund</PButton
            >
        </template>
    </PModal>
</template>
