<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PModal from '@/components/admin/PModal.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import { currency, date, statusTone, titleCase } from '@/lib/format';

type Cancellation = {
    id: number;
    number: string;
    scope: string;
    reason: string;
    note: string | null;
    status: string;
    total_amount: string;
    restock: boolean;
    refund_requested: boolean;
    requested_by: string;
    review_note: string | null;
    reviewed_at: string | null;
    created_at: string;
    order: {
        id: number;
        number: string;
        grand_total: string;
        status: string;
        items: { id: number; name: string; sku: string | null }[];
    };
    customer: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    } | null;
    reviewer: { name: string } | null;
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
    refunds: {
        id: number;
        number: string;
        status: string;
        total_amount: string;
    }[];
};

const props = defineProps<{
    cancellation: Cancellation;
    reasons: Record<string, string>;
}>();

const showApprove = ref(false);
const showReject = ref(false);

const approveForm = useForm({
    review_note: '',
    restock: props.cancellation.restock,
});
const rejectForm = useForm({ review_note: '' });

const approve = () =>
    approveForm.patch(`/admin/cancellations/${props.cancellation.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => (showApprove.value = false),
    });

const reject = () =>
    rejectForm.patch(`/admin/cancellations/${props.cancellation.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => (showReject.value = false),
    });
</script>

<template>
    <Head :title="`Cancellation ${cancellation.number}`" />

    <PageHeader
        :title="cancellation.number"
        :subtitle="`Requested ${date(cancellation.created_at, true)} by ${titleCase(cancellation.requested_by)}`"
        back-href="/admin/cancellations"
    >
        <template #badge>
            <PBadge :tone="statusTone(cancellation.status)" dot>{{
                titleCase(cancellation.status)
            }}</PBadge>
            <PBadge tone="new"
                >{{ titleCase(cancellation.scope) }} cancellation</PBadge
            >
        </template>
        <template #actions>
            <template v-if="cancellation.status === 'pending'">
                <PButton variant="critical" @click="showReject = true"
                    >Reject</PButton
                >
                <PButton variant="primary" @click="showApprove = true"
                    >Approve</PButton
                >
            </template>
            <PButton
                v-else-if="
                    cancellation.status === 'approved' &&
                    !cancellation.refunds.length
                "
                :href="`/admin/refunds/create?order=${cancellation.order.id}`"
                variant="primary"
            >
                Create refund
            </PButton>
        </template>
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <PCard title="Items to cancel">
                <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                    <li
                        v-for="line in cancellation.items"
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
                                · {{ line.quantity }} of
                                {{ line.order_item.quantity }} ordered
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-[13px] font-semibold tabular-nums"
                            >{{ currency(line.amount) }}</span
                        >
                    </li>
                </ul>

                <template #footer>
                    <div class="flex items-center justify-between text-[13px]">
                        <span class="text-[#616161] dark:text-[#b5b5b5]"
                            >Total cancellation value</span
                        >
                        <span class="text-base font-semibold tabular-nums">{{
                            currency(cancellation.total_amount)
                        }}</span>
                    </div>
                </template>
            </PCard>

            <PCard title="Reason">
                <p
                    class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{
                        reasons[cancellation.reason] ??
                        titleCase(cancellation.reason)
                    }}
                </p>
                <p
                    v-if="cancellation.note"
                    class="mt-1.5 text-[13px] whitespace-pre-line text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ cancellation.note }}
                </p>
            </PCard>

            <PCard v-if="cancellation.review_note" title="Review decision">
                <p
                    class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]"
                >
                    {{ cancellation.review_note }}
                </p>
                <p class="mt-1.5 text-xs text-[#8a8a8a]">
                    {{ titleCase(cancellation.status) }}
                    <span v-if="cancellation.reviewer">
                        by {{ cancellation.reviewer.name }}</span
                    >
                    <span v-if="cancellation.reviewed_at">
                        · {{ date(cancellation.reviewed_at, true) }}</span
                    >
                </p>
            </PCard>

            <PCard v-if="cancellation.refunds.length" title="Linked refunds">
                <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                    <li
                        v-for="refund in cancellation.refunds"
                        :key="refund.id"
                        class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                    >
                        <Link
                            :href="`/admin/refunds/${refund.id}`"
                            class="text-[13px] font-medium hover:underline"
                            >{{ refund.number }}</Link
                        >
                        <PBadge :tone="statusTone(refund.status)">{{
                            titleCase(refund.status)
                        }}</PBadge>
                        <span class="text-[13px] tabular-nums">{{
                            currency(refund.total_amount)
                        }}</span>
                    </li>
                </ul>
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard title="Order">
                <Link
                    :href="`/admin/orders/${cancellation.order.id}`"
                    class="text-[13px] font-semibold text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                >
                    {{ cancellation.order.number }}
                </Link>
                <dl class="mt-2 space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Order total
                        </dt>
                        <dd class="tabular-nums">
                            {{ currency(cancellation.order.grand_total) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Order status
                        </dt>
                        <dd>
                            <PBadge
                                :tone="statusTone(cancellation.order.status)"
                                >{{
                                    titleCase(cancellation.order.status)
                                }}</PBadge
                            >
                        </dd>
                    </div>
                </dl>
            </PCard>

            <PCard title="Customer">
                <div v-if="cancellation.customer">
                    <Link
                        :href="`/admin/customers/${cancellation.customer.id}`"
                        class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ cancellation.customer.first_name }}
                        {{ cancellation.customer.last_name }}
                    </Link>
                    <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                        {{ cancellation.customer.email }}
                    </p>
                </div>
                <p v-else class="text-[13px] text-[#8a8a8a]">Guest order</p>
            </PCard>

            <PCard title="Handling">
                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Restock items
                        </dt>
                        <dd>
                            <PBadge
                                :tone="
                                    cancellation.restock ? 'success' : 'neutral'
                                "
                                >{{
                                    cancellation.restock ? 'Yes' : 'No'
                                }}</PBadge
                            >
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Refund requested
                        </dt>
                        <dd>
                            <PBadge
                                :tone="
                                    cancellation.refund_requested
                                        ? 'attention'
                                        : 'neutral'
                                "
                                >{{
                                    cancellation.refund_requested ? 'Yes' : 'No'
                                }}</PBadge
                            >
                        </dd>
                    </div>
                </dl>
            </PCard>
        </div>
    </div>

    <PModal
        :open="showApprove"
        title="Approve cancellation"
        size="small"
        @close="showApprove = false"
    >
        <div class="space-y-3">
            <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                Approving cancels {{ cancellation.items.length }} line item(s)
                worth <strong>{{ currency(cancellation.total_amount) }}</strong
                >.
            </p>
            <PCheckbox
                v-model="approveForm.restock"
                label="Return items to inventory"
            />
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
                >Approve cancellation</PButton
            >
        </template>
    </PModal>

    <PModal
        :open="showReject"
        title="Reject cancellation"
        size="small"
        @close="showReject = false"
    >
        <PTextarea
            v-model="rejectForm.review_note"
            label="Reason for rejection"
            :rows="4"
            :error="rejectForm.errors.review_note"
            help-text="This is stored on the order timeline."
        />
        <template #footer>
            <PButton @click="showReject = false">Cancel</PButton>
            <PButton
                variant="critical"
                :loading="rejectForm.processing"
                @click="reject"
                >Reject request</PButton
            >
        </template>
    </PModal>
</template>
