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
import { currency, date, number, statusTone, titleCase } from '@/lib/format';

type Item = {
    id: number;
    name: string;
    sku: string | null;
    quantity: number;
    quantity_cancelled: number;
    quantity_fulfilled: number;
    unit_price: string;
    total: string;
    vendor_earning: string;
    image_url: string | null;
    options: Record<string, string> | null;
    product: { id: number; name: string } | null;
};

type Event = {
    id: number;
    type: string;
    title: string;
    body: string | null;
    /** "store" when we wrote it, "marketplace" when the admin did. */
    by: 'store' | 'marketplace';
    created_at: string;
};

type Address = Record<string, string | null> | null;

const props = defineProps<{
    order: {
        id: number;
        number: string;
        status: string;
        payment_status: string;
        fulfillment_status: string;
        placed_at: string | null;
        tracking_number: string | null;
        carrier: string | null;
        shipping_address: Address;
        customer: {
            id: number;
            first_name: string;
            last_name: string;
            email: string;
            phone: string | null;
        } | null;
        items: Item[];
    };
    timeline: Event[];
    mine: { items: number; total: number; commission: number; earning: number };
    sharedBasket: boolean;
    deliveryPartners: string[];
    trackingUrl: string | null;
}>();

const outstanding = (item: Item) =>
    Math.max(
        0,
        item.quantity - item.quantity_cancelled - item.quantity_fulfilled,
    );

const toPack = computed(() =>
    props.order.items.reduce((sum, item) => sum + outstanding(item), 0),
);

const fulfilForm = useForm({
    items: props.order.items.map((item) => ({
        id: item.id,
        quantity: item.quantity - item.quantity_cancelled,
    })),
    tracking_number: props.order.tracking_number ?? '',
    carrier: props.order.carrier ?? '',
});

const submitFulfil = () =>
    fulfilForm.post(`/seller/orders/${props.order.id}/fulfill`, {
        preserveScroll: true,
    });

const noteForm = useForm({ note: '' });

const submitNote = () =>
    noteForm.post(`/seller/orders/${props.order.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });

const carrierOptions = computed(() => [
    { value: '', label: 'No carrier' },
    ...props.deliveryPartners.map((name) => ({ value: name, label: name })),
]);

const addressLines = computed(() => {
    const a = props.order.shipping_address;

    if (!a) {
        return [];
    }

    return [
        [a.first_name, a.last_name].filter(Boolean).join(' '),
        a.line1,
        a.line2,
        [a.city, a.state, a.postcode].filter(Boolean).join(', '),
        a.country,
        a.phone,
    ].filter((line): line is string => !!line && line.trim() !== '');
});

const showTimeline = ref(true);
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <PageHeader :title="`Order ${order.number}`" back-href="/seller/orders">
        <template #badge>
            <PBadge :tone="statusTone(order.status)" dot>
                {{ titleCase(order.status) }}
            </PBadge>
        </template>
    </PageHeader>

    <p class="mb-4 text-[13px] text-[#616161] dark:text-[#b5b5b5]">
        Placed {{ date(order.placed_at, true) }}
    </p>

    <div
        v-if="sharedBasket"
        class="mb-4 rounded-xl border border-[#ffd6a4] bg-[#fff1c9] px-4 py-3 text-[13px] text-[#5e4200]"
    >
        This basket is shared with another seller. You are only shown your own
        lines, and every figure below is your share — not the order total.
    </div>

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 space-y-4">
            <!-- Items + fulfilment -->
            <PCard title="Your items">
                <template #actions>
                    <PBadge :tone="toPack ? 'warning' : 'success'" dot>
                        {{
                            toPack
                                ? `${number(toPack)} still to pack`
                                : 'Nothing left to pack'
                        }}
                    </PBadge>
                </template>

                <form class="space-y-3" @submit.prevent="submitFulfil">
                    <ul class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                        <li
                            v-for="(item, index) in order.items"
                            :key="item.id"
                            class="flex flex-wrap items-center gap-3 py-3"
                        >
                            <img
                                v-if="item.image_url"
                                :src="item.image_url"
                                :alt="item.name"
                                class="size-11 shrink-0 rounded-md object-cover"
                            />
                            <span
                                v-else
                                class="size-11 shrink-0 rounded-md bg-[#f1f1f1] dark:bg-[#303030]"
                            />

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13px] font-medium"
                                    >{{ item.name }}</span
                                >
                                <span class="block text-xs text-[#8a8a8a]">
                                    {{ item.sku || 'No SKU' }} ·
                                    {{ number(item.quantity) }} ordered ·
                                    {{ number(item.quantity_fulfilled) }} packed
                                </span>
                            </span>

                            <span class="text-right text-[13px]">
                                {{ currency(item.total) }}
                            </span>

                            <span class="w-24">
                                <PTextField
                                    v-model="fulfilForm.items[index].quantity"
                                    type="number"
                                    min="0"
                                    :max="
                                        item.quantity - item.quantity_cancelled
                                    "
                                />
                            </span>
                        </li>
                    </ul>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="fulfilForm.tracking_number"
                            label="Tracking number"
                            :error="fulfilForm.errors.tracking_number"
                        />
                        <PSelect
                            v-model="fulfilForm.carrier"
                            label="Carrier"
                            :options="carrierOptions"
                            :error="fulfilForm.errors.carrier"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <PButton
                            type="submit"
                            variant="primary"
                            :loading="fulfilForm.processing"
                            :disabled="fulfilForm.processing"
                        >
                            Mark as packed
                        </PButton>
                        <!--
                        | The label, which is the thing that goes on the box.
                        | A plain link because the server redirects to the
                        | courier's own PDF — putting a viewer around it would
                        | add a click between a packing table and a printer.
                        -->
                        <a
                            v-if="order.tracking_number"
                            :href="`/seller/orders/${order.id}/label`"
                            target="_blank"
                            rel="noopener"
                            class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                        >
                            Print label
                        </a>
                        <!--
                        | The store's own tax invoice for this order. The
                        | marketplace did not sell these goods, this store did,
                        | so the document carries this store's name and GSTIN —
                        | and on a basket shared with another seller it holds
                        | only this store's lines.
                        -->
                        <a
                            v-if="
                                !['pending', 'cancelled'].includes(order.status)
                            "
                            :href="`/seller/orders/${order.id}/invoice`"
                            target="_blank"
                            rel="noopener"
                            class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                        >
                            Print invoice
                        </a>
                        <a
                            v-if="trackingUrl"
                            :href="trackingUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-[13px] font-medium text-[#005bd3] hover:underline dark:text-[#8ac1ff]"
                        >
                            Track shipment
                        </a>
                    </div>
                </form>
            </PCard>

            <!-- Timeline -->
            <PCard title="Timeline">
                <template #actions>
                    <PButton size="slim" @click="showTimeline = !showTimeline">
                        {{ showTimeline ? 'Hide' : 'Show' }}
                    </PButton>
                </template>

                <form class="mb-3 space-y-2" @submit.prevent="submitNote">
                    <PTextarea
                        v-model="noteForm.note"
                        :rows="2"
                        placeholder="Leave a note for the marketplace team…"
                        :error="noteForm.errors.note"
                    />
                    <PButton
                        type="submit"
                        size="slim"
                        :loading="noteForm.processing"
                        :disabled="noteForm.processing || !noteForm.note.trim()"
                    >
                        Add note
                    </PButton>
                </form>

                <ol
                    v-if="showTimeline && timeline.length"
                    class="space-y-3 border-l border-[#e3e3e3] pl-4 dark:border-[#3a3a3a]"
                >
                    <li v-for="event in timeline" :key="event.id">
                        <p class="text-[13px] font-medium">{{ event.title }}</p>
                        <p
                            v-if="event.body"
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{ event.body }}
                        </p>
                        <p class="text-xs text-[#8a8a8a]">
                            {{ date(event.created_at, true) }} ·
                            {{ event.by === 'store' ? 'You' : 'Marketplace' }}
                        </p>
                    </li>
                </ol>
                <p v-else-if="showTimeline" class="text-[13px] text-[#8a8a8a]">
                    Nothing recorded yet.
                </p>
            </PCard>
        </div>

        <!-- Sidebar -->
        <div class="space-y-4">
            <PCard title="Your share">
                <dl class="space-y-1.5 text-[13px]">
                    <div class="flex justify-between">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Items
                        </dt>
                        <dd>{{ number(mine.items) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Sales
                        </dt>
                        <dd>{{ currency(mine.total) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-[#616161] dark:text-[#b5b5b5]">
                            Commission
                        </dt>
                        <dd>−{{ currency(mine.commission) }}</dd>
                    </div>
                    <div
                        class="flex justify-between border-t border-[#e3e3e3] pt-1.5 font-semibold dark:border-[#3a3a3a]"
                    >
                        <dt>You earn</dt>
                        <dd>{{ currency(mine.earning) }}</dd>
                    </div>
                </dl>
            </PCard>

            <PCard title="Ship to">
                <address
                    v-if="addressLines.length"
                    class="space-y-0.5 text-[13px] not-italic"
                >
                    <span
                        v-for="line in addressLines"
                        :key="line"
                        class="block"
                    >
                        {{ line }}
                    </span>
                </address>
                <p v-else class="text-[13px] text-[#8a8a8a]">
                    No shipping address on this order.
                </p>
            </PCard>

            <PCard title="Customer">
                <p class="text-[13px] font-medium">
                    {{
                        order.customer
                            ? `${order.customer.first_name} ${order.customer.last_name}`.trim()
                            : 'Guest'
                    }}
                </p>
                <p
                    v-if="order.customer"
                    class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ order.customer.email }}
                </p>
                <p class="mt-2 text-xs text-[#8a8a8a]">
                    Customer records belong to the marketplace — contact support
                    if something needs changing.
                </p>
            </PCard>
        </div>
    </div>
</template>
