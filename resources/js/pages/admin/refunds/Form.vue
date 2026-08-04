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
        refunded_total: string;
        shipping_total: string;
        items: OrderItem[];
    };
    reasons: Record<string, string>;
    methods: Record<string, string>;
}>();

const available = (item: OrderItem) =>
    item.quantity - item.quantity_cancelled - item.quantity_refunded;

const form = useForm({
    order_id: props.order.id,
    reason: 'damaged',
    method: 'original',
    note: '',
    restock: true,
    shipping_amount: 0,
    adjustment_amount: 0,
    items: props.order.items.map((item) => ({
        order_item_id: item.id,
        quantity: 0,
    })),
});

const itemsTotal = computed(() =>
    form.items.reduce((sum, row, index) => {
        const item = props.order.items[index];
        const unit = Number(item.total) / Math.max(item.quantity, 1);

        return sum + unit * Number(row.quantity || 0);
    }, 0),
);

const refundable = computed(
    () => Number(props.order.grand_total) - Number(props.order.refunded_total),
);

const estimatedTotal = computed(() =>
    Math.min(
        itemsTotal.value +
            Number(form.shipping_amount || 0) +
            Number(form.adjustment_amount || 0),
        refundable.value,
    ),
);

const selectAll = () =>
    form.items.forEach(
        (row, index) => (row.quantity = available(props.order.items[index])),
    );

const refundEverything = () => {
    selectAll();
    form.shipping_amount = Number(props.order.shipping_total);
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        items: data.items.filter((row) => Number(row.quantity) > 0),
    })).post('/admin/refunds');
};
</script>

<template>
    <Head :title="`Refund · ${order.number}`" />

    <form @submit.prevent="submit">
        <PageHeader
            title="Create refund"
            :subtitle="`Order ${order.number}`"
            :back-href="`/admin/orders/${order.id}`"
        >
            <template #actions>
                <PButton :href="`/admin/orders/${order.id}`">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="estimatedTotal <= 0 || form.processing"
                >
                    Create refund
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard
                    title="Select items"
                    subtitle="Set how many units of each line to refund"
                >
                    <template #actions>
                        <PButton size="slim" @click="selectAll"
                            >All items</PButton
                        >
                        <PButton size="slim" @click="refundEverything"
                            >Full refund</PButton
                        >
                    </template>

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
                                    {{ available(item) }} refundable
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

                <PCard title="Additional amounts">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="form.shipping_amount"
                            label="Refund shipping"
                            type="number"
                            step="0.01"
                            min="0"
                            prefix="₹"
                            :error="form.errors.shipping_amount"
                            :help-text="`Order shipping was ${currency(order.shipping_total)}`"
                        />
                        <PTextField
                            v-model="form.adjustment_amount"
                            label="Adjustment"
                            type="number"
                            step="0.01"
                            prefix="₹"
                            :error="form.errors.adjustment_amount"
                            help-text="Use a negative value to deduct (e.g. restocking fee)."
                        />
                    </div>
                </PCard>

                <PCard title="Reason">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.reason"
                            label="Refund reason"
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
                                Already refunded
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(order.refunded_total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Refundable
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(refundable) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 pt-1.5">
                            <dt class="text-[#616161] dark:text-[#b5b5b5]">
                                Items selected
                            </dt>
                            <dd class="tabular-nums">
                                {{ currency(itemsTotal) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-3 border-t border-[#e3e3e3] pt-1.5 dark:border-[#3a3a3a]"
                        >
                            <dt class="font-semibold">Refund total</dt>
                            <dd class="text-base font-semibold tabular-nums">
                                {{ currency(estimatedTotal) }}
                            </dd>
                        </div>
                    </dl>
                </PCard>

                <PCard title="Payment">
                    <div class="space-y-3">
                        <PSelect
                            v-model="form.method"
                            label="Refund via"
                            :options="
                                Object.entries(methods).map(
                                    ([value, label]) => ({ value, label }),
                                )
                            "
                            :error="form.errors.method"
                        />
                        <PCheckbox
                            v-model="form.restock"
                            label="Restock refunded items"
                            help-text="Applied when the refund is processed."
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
