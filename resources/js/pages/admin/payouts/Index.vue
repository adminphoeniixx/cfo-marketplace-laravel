<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PModal from '@/components/admin/PModal.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTable from '@/components/admin/PTable.vue';
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

type Payout = {
    id: number;
    number: string;
    period_start: string;
    period_end: string;
    gross_sales: string;
    commission_amount: string;
    adjustment_amount: string;
    net_amount: string;
    orders_count: number;
    status: string;
    method: string;
    transaction_reference: string | null;
    paid_at: string | null;
    vendor: { id: number; name: string; payout_method: string } | null;
};

const props = defineProps<{
    payouts: {
        data: Payout[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    vendors: { id: number; name: string }[];
    statuses: string[];
    counts: Record<string, number>;
    summary: { pending: number; paid: number; commission_earned: number };
}>();

const showCreate = ref(false);
const marking = ref<Payout | null>(null);

const createForm = useForm({
    vendor_id: props.vendors[0]?.id ?? 0,
    period_start: new Date(Date.now() - 30 * 86400000)
        .toISOString()
        .slice(0, 10),
    period_end: new Date().toISOString().slice(0, 10),
    adjustment_amount: 0,
    note: '',
});

const markForm = useForm({ status: 'paid', transaction_reference: '' });

const openMark = (payout: Payout) => {
    marking.value = payout;
    markForm.clearErrors();
    Object.assign(markForm, {
        status: 'paid',
        transaction_reference: payout.transaction_reference ?? '',
    });
};

const submitCreate = () =>
    createForm.post('/admin/payouts', {
        onSuccess: () => (showCreate.value = false),
    });

const submitMark = () => {
    if (!marking.value) {
        return;
    }

    markForm.patch(`/admin/payouts/${marking.value.id}/status`, {
        preserveScroll: true,
        onSuccess: () => (marking.value = null),
    });
};

const remove = (payout: Payout) =>
    router.delete(`/admin/payouts/${payout.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Payouts" />

    <PageHeader
        title="Vendor payouts"
        subtitle="Settle vendor earnings after commission"
    >
        <template #actions>
            <PButton href="/admin/vendors">Vendors</PButton>
            <PButton variant="primary" @click="showCreate = true"
                >Generate payout</PButton
            >
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard
            label="Outstanding"
            :value="compactCurrency(summary.pending)"
            caption="pending + processing"
        />
        <MetricCard
            label="Paid out"
            :value="compactCurrency(summary.paid)"
            caption="all time"
        />
        <MetricCard
            label="Commission earned"
            :value="compactCurrency(summary.commission_earned)"
            caption="across payouts"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/payouts"
            :filters="filters"
            search-placeholder="Search payout or vendor"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                ...statuses.map((status) => ({
                    value: status,
                    label: titleCase(status),
                    count: counts[status] ?? 0,
                })),
            ]"
        >
            <template #default="{ apply }">
                <select
                    class="rounded-lg border border-[#8a8a8a] bg-white px-2 py-1.5 text-[13px] text-[#303030] outline-none dark:border-[#616161] dark:bg-[#303030] dark:text-[#e3e3e3]"
                    :value="filters.vendor ?? ''"
                    @change="
                        apply({
                            vendor:
                                ($event.target as HTMLSelectElement).value ||
                                undefined,
                            page: undefined,
                        })
                    "
                >
                    <option value="">All vendors</option>
                    <option
                        v-for="vendor in vendors"
                        :key="vendor.id"
                        :value="vendor.id"
                    >
                        {{ vendor.name }}
                    </option>
                </select>
            </template>
        </PFilters>

        <PTable
            v-if="payouts.data.length"
            :headers="[
                'Payout',
                'Vendor',
                'Period',
                'Orders',
                'Gross',
                'Commission',
                'Net',
                'Status',
                '',
            ]"
            :align-right="[3, 4, 5, 6, 8]"
        >
            <tr
                v-for="payout in payouts.data"
                :key="payout.id"
                class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
            >
                <td class="px-4 py-2.5">
                    <p
                        class="text-[13px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
                    >
                        {{ payout.number }}
                    </p>
                    <p class="text-xs text-[#8a8a8a]">
                        {{ titleCase(payout.method) }}
                    </p>
                </td>
                <td class="px-4 py-2.5">
                    <Link
                        v-if="payout.vendor"
                        :href="`/admin/vendors/${payout.vendor.id}`"
                        class="text-[13px] text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ payout.vendor.name }}
                    </Link>
                    <span v-else class="text-[13px] text-[#8a8a8a]">—</span>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] whitespace-nowrap text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ date(payout.period_start) }} –
                    {{ date(payout.period_end) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    {{ number(payout.orders_count) }}
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                    {{ currency(payout.gross_sales) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#616161] tabular-nums dark:text-[#b5b5b5]"
                >
                    −{{ currency(payout.commission_amount) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] font-semibold text-[#303030] tabular-nums dark:text-[#e3e3e3]"
                >
                    {{ currency(payout.net_amount) }}
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="statusTone(payout.status)" dot>{{
                        titleCase(payout.status)
                    }}</PBadge>
                    <p
                        v-if="payout.paid_at"
                        class="mt-0.5 text-xs text-[#8a8a8a]"
                    >
                        {{ date(payout.paid_at) }}
                    </p>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <PButton
                            v-if="payout.status !== 'paid'"
                            size="slim"
                            variant="primary"
                            @click="openMark(payout)"
                            >Mark paid</PButton
                        >
                        <PButton
                            v-if="payout.status !== 'paid'"
                            size="slim"
                            variant="plain"
                            @click="remove(payout)"
                            >Delete</PButton
                        >
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No payouts yet"
            description="Generate a payout to settle a vendor's earnings for a period."
        >
            <template #action>
                <PButton variant="primary" @click="showCreate = true"
                    >Generate payout</PButton
                >
            </template>
        </PEmptyState>

        <PPagination
            :links="payouts.links"
            :from="payouts.from"
            :to="payouts.to"
            :total="payouts.total"
        />
    </PCard>

    <PModal
        :open="showCreate"
        title="Generate payout"
        size="medium"
        @close="showCreate = false"
    >
        <div class="space-y-3">
            <PSelect
                v-model="createForm.vendor_id"
                label="Vendor"
                :options="vendors.map((v) => ({ value: v.id, label: v.name }))"
                :error="createForm.errors.vendor_id"
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <PTextField
                    v-model="createForm.period_start"
                    label="Period start"
                    type="date"
                    :error="createForm.errors.period_start"
                />
                <PTextField
                    v-model="createForm.period_end"
                    label="Period end"
                    type="date"
                    :error="createForm.errors.period_end"
                />
            </div>
            <PTextField
                v-model="createForm.adjustment_amount"
                label="Adjustment"
                type="number"
                step="0.01"
                prefix="₹"
                :error="createForm.errors.adjustment_amount"
                help-text="Bonuses add, penalties subtract (use a negative number)."
            />
            <PTextarea
                v-model="createForm.note"
                label="Note"
                :rows="2"
                :error="createForm.errors.note"
            />
        </div>
        <template #footer>
            <PButton @click="showCreate = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="createForm.processing"
                @click="submitCreate"
                >Generate payout</PButton
            >
        </template>
    </PModal>

    <PModal
        :open="!!marking"
        title="Update payout status"
        size="small"
        @close="marking = null"
    >
        <div class="space-y-3">
            <p class="text-[13px] text-[#303030] dark:text-[#e3e3e3]">
                Settle
                <strong>{{ currency(marking?.net_amount ?? 0) }}</strong> to
                {{ marking?.vendor?.name }}.
            </p>
            <PSelect
                v-model="markForm.status"
                label="Status"
                :options="
                    statuses.map((status) => ({
                        value: status,
                        label: titleCase(status),
                    }))
                "
                :error="markForm.errors.status"
            />
            <PTextField
                v-model="markForm.transaction_reference"
                label="Transaction reference"
                :error="markForm.errors.transaction_reference"
            />
        </div>
        <template #footer>
            <PButton @click="marking = null">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="markForm.processing"
                @click="submitMark"
                >Save</PButton
            >
        </template>
    </PModal>
</template>
