<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { currency } from '@/lib/format';

type OrderItem = {
    id: number;
    name: string;
    sku: string | null;
    unit_price: string;
    total: string;
    quantity: number;
    quantity_cancelled: number;
    quantity_refunded: number;
};

const props = defineProps<{
    order: {
        id: number;
        number: string;
        grand_total: string;
        items: OrderItem[];
    };
    reasons: Record<string, string>;
}>();

const available = (item: OrderItem) =>
    item.quantity - item.quantity_cancelled - item.quantity_refunded;

const form = useForm({
    order_id: props.order.id,
    reason: 'customer_changed_mind',
    note: '',
    restock: true,
    refund_requested: false,
    requested_by: 'admin',
    items: props.order.items.map((item) => ({
        order_item_id: item.id,
        quantity: 0,
    })),
});

const estimatedTotal = computed(() =>
    form.items.reduce((sum, row, index) => {
        const item = props.order.items[index];
        const unit = Number(item.total) / Math.max(item.quantity, 1);

        return sum + unit * Number(row.quantity || 0);
    }, 0),
);

const selectedCount = computed(
    () => form.items.filter((row) => Number(row.quantity) > 0).length,
);

const selectAll = () => {
    form.items.forEach(
        (row, index) => (row.quantity = available(props.order.items[index])),
    );
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        items: data.items.filter((row) => Number(row.quantity) > 0),
    })).post('/admin/cancellations');
};
</script>

<template>
    <Head :title="`Cancel items · ${order.number}`" />

    <form @submit.prevent="submit">
        <PageHeader
            title="Cancel items"
            :subtitle="`Order ${order.number}`"
            :back-href="`/admin/orders/${order.id}`"
        >
            <template #actions>
                <PButton :href="`/admin/orders/${order.id}`">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="!selectedCount || form.processing"
                >
                    Create cancellation
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard
                    title="Select items"
                    subtitle="Choose the quantity to cancel from each line"
                >
                    <template #actions>
                        <PButton size="slim" @click="selectAll"
                            >Select everything</PButton
                        >
                    </template>

                    <p
                        v-if="form.errors.items"
                        class="mb-2 text-xs text-[#e51c00]"
                    >
                        {{ form.errors.items }}
                    </p>

                    <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                        <li
                            v-for="(item, index) in order.items"
                            :key="item.id"
                            class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                                >
                                    {{ item.name }}
                                </p>
                                <p class="text-xs text-[#8a8a8a]">
                                    {{ item.sku || 'No SKU' }} ·
                                    {{ currency(item.unit_price) }} each ·
                                    {{ available(item) }} of
                                    {{ item.quantity }} available to cancel
                                </p>
                            </div>
                            <div class="w-28 shrink-0">
                                <PTextField
                                    v-model="form.items[index].quantity"
                                    type="number"
                                    min="0"
                                    :max="available(item)"
                                    :disabled="available(item) < 1"
                                />
                            </div>
                        </li>
                    </ul>
                </PCard>

                <PCard title="Reason">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.reason"
                            label="Cancellation reason"
                            :options="
                                Object.entries(reasons).map(
                                    ([value, label]) => ({ value, label }),
                                )
                            "
                            :error="form.errors.reason"
                        />
                        <PTextarea
                            v-model="form.note"
                            label="Note"
                            :rows="3"
                            :error="form.errors.note"
                            placeholder="Any context worth recording"
                        />
                    </div>
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Summary">
                    <dl class="space-y-1.5 text-[13px]">
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Order total
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(order.grand_total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Lines selected
                            </dt>
                            <dd class="tabular-nums">{{ selectedCount }}</dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 border-t border-[#e3e3e3] pt-1.5 font-semibold dark:border-[#3a3a3a]"
                        >
                            <dt>Cancellation value</dt>
                            <dd class="tabular-nums">
                                {{ currency(estimatedTotal) }}
                            </dd>
                        </div>
                    </dl>
                </PCard>

                <PCard title="Options">
                    <div class="space-y-2.5">
                        <PCheckbox
                            v-model="form.restock"
                            label="Restock cancelled items"
                            help-text="Applied when the request is approved."
                        />
                        <PCheckbox
                            v-model="form.refund_requested"
                            label="Customer also wants a refund"
                            help-text="Flags this request so you can raise a refund next."
                        />
                        <PSelect
                            v-model="form.requested_by"
                            label="Requested by"
                            :options="[
                                { value: 'admin', label: 'Admin' },
                                { value: 'customer', label: 'Customer' },
                                { value: 'vendor', label: 'Vendor' },
                                { value: 'system', label: 'System' },
                            ]"
                            :error="form.errors.requested_by"
                        />
                    </div>
                </PCard>

                <PCard title="Order">
                    <Link
                        :href="`/admin/orders/${order.id}`"
                        class="text-[13px] font-semibold text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                    >
                        {{ order.number }}
                    </Link>
                </PCard>
            </div>
        </div>
    </form>
</template>
