<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import { currency } from '@/lib/format';

type Message = {
    id: number;
    body: string;
    author: string;
    author_type: string;
    is_internal: boolean;
    created_at: string | null;
};

const props = defineProps<{
    ticket: {
        id: number;
        number: string;
        subject: string;
        status: string;
        priority: string;
        category_label: string;
        assigned_to: number | null;
        waiting_hours: number | null;
        hours_to_first_reply: number | null;
        created_at: string | null;
        customer: {
            id: number;
            name: string;
            email: string | null;
            phone: string | null;
            orders_count: number;
            total_spent: number;
        } | null;
        order: {
            id: number;
            number: string;
            status: string;
            grand_total: number;
            placed_at: string | null;
        } | null;
        messages: Message[];
    };
    categories: Record<string, string>;
    statuses: string[];
    priorities: string[];
    assignees: { id: number; name: string }[];
}>();

const reply = useForm({
    body: '',
    is_internal: false,
    resolve: false,
});

const send = () =>
    reply.post(`/admin/tickets/${props.ticket.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });

/** One field at a time, saved the moment it is picked. */
const set = (fields: Record<string, string | number | null>) =>
    router.patch(`/admin/tickets/${props.ticket.id}`, fields, { preserveScroll: true });

const at = (value: string | null) =>
    value
        ? new Date(value).toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';

const tone = (status: string) =>
    status === 'open'
        ? 'attention'
        : status === 'pending'
          ? 'info'
          : status === 'resolved'
            ? 'success'
            : 'neutral';
</script>

<template>
    <Head :title="`${ticket.number} — ${ticket.subject}`" />

    <PageHeader :title="ticket.subject" :subtitle="`${ticket.number} · ${ticket.category_label}`">
        <template #actions>
            <PBadge :tone="tone(ticket.status)">{{ ticket.status }}</PBadge>
        </template>
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <PCard title="Conversation">
                <div class="space-y-3">
                    <div
                        v-for="message in ticket.messages"
                        :key="message.id"
                        class="rounded-lg border p-3"
                        :class="
                            message.is_internal
                                ? 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/30'
                                : message.author_type === 'support'
                                  ? 'border-[#e3e3e3] bg-[#fafafa] dark:border-[#2a2a2a] dark:bg-[#282828]'
                                  : 'border-[#e3e3e3] bg-white dark:border-[#2a2a2a] dark:bg-[#232323]'
                        "
                    >
                        <div class="mb-1 flex items-center gap-2 text-xs text-[#8a8a8a]">
                            <strong class="text-[#303030] dark:text-[#e3e3e3]">
                                {{ message.author }}
                            </strong>
                            <PBadge v-if="message.is_internal" tone="warning">
                                Internal note
                            </PBadge>
                            <span>{{ at(message.created_at) }}</span>
                        </div>
                        <p class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]">
                            {{ message.body }}
                        </p>
                    </div>
                </div>
            </PCard>

            <PCard title="Reply">
                <div class="space-y-3">
                    <PTextarea
                        v-model="reply.body"
                        :rows="5"
                        :error="reply.errors.body"
                        :placeholder="
                            reply.is_internal
                                ? 'A note for the rest of the desk. The shopper never sees this.'
                                : 'What the shopper will read, and be emailed.'
                        "
                    />
                    <PCheckbox
                        v-model="reply.is_internal"
                        label="Internal note"
                        help-text="Kept between staff. It does not move the ticket or notify anybody."
                    />
                    <PCheckbox
                        v-if="!reply.is_internal"
                        v-model="reply.resolve"
                        label="Mark resolved after sending"
                        help-text="The shopper can still write back, which reopens it."
                    />
                    <div class="flex justify-end">
                        <PButton
                            variant="primary"
                            :loading="reply.processing"
                            :disabled="!reply.body.trim()"
                            @click="send"
                        >
                            {{ reply.is_internal ? 'Add note' : 'Send reply' }}
                        </PButton>
                    </div>
                </div>
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard title="Handling">
                <div class="space-y-3">
                    <PSelect
                        :model-value="ticket.status"
                        label="Status"
                        :options="statuses.map((s) => ({ value: s, label: s }))"
                        @update:model-value="(v) => set({ status: v })"
                    />
                    <PSelect
                        :model-value="ticket.priority"
                        label="Priority"
                        :options="priorities.map((p) => ({ value: p, label: p }))"
                        @update:model-value="(v) => set({ priority: v })"
                    />
                    <PSelect
                        :model-value="ticket.assigned_to ?? ''"
                        label="Assigned to"
                        :options="[
                            { value: '', label: 'Nobody' },
                            ...assignees.map((a) => ({ value: a.id, label: a.name })),
                        ]"
                        @update:model-value="(v) => set({ assigned_to: v === '' ? null : v })"
                    />
                </div>
            </PCard>

            <PCard title="Shopper">
                <div v-if="ticket.customer" class="space-y-1 text-[13px]">
                    <p class="font-medium">{{ ticket.customer.name }}</p>
                    <p class="text-[#8a8a8a]">{{ ticket.customer.email }}</p>
                    <p v-if="ticket.customer.phone" class="text-[#8a8a8a]">
                        {{ ticket.customer.phone }}
                    </p>
                    <p class="pt-2 text-[#616161] dark:text-[#b5b5b5]">
                        {{ ticket.customer.orders_count }} order(s) ·
                        {{ currency(ticket.customer.total_spent) }} spent
                    </p>
                    <a
                        class="inline-block pt-1 text-[13px] underline"
                        :href="`/admin/customers/${ticket.customer.id}`"
                    >
                        Open customer
                    </a>
                </div>
            </PCard>

            <PCard v-if="ticket.order" title="About this order">
                <div class="space-y-1 text-[13px]">
                    <p class="font-medium">{{ ticket.order.number }}</p>
                    <p class="text-[#8a8a8a]">
                        {{ ticket.order.status }} · {{ currency(ticket.order.grand_total) }}
                    </p>
                    <a
                        class="inline-block pt-1 underline"
                        :href="`/admin/orders/${ticket.order.id}`"
                    >
                        Open order
                    </a>
                </div>
            </PCard>

            <PCard title="Timing">
                <div class="space-y-1 text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    <p>Opened {{ at(ticket.created_at) }}</p>
                    <p>
                        First reply:
                        <template v-if="ticket.hours_to_first_reply !== null">
                            after {{ ticket.hours_to_first_reply }}h
                        </template>
                        <template v-else>
                            <!-- Not zero. Nobody has answered yet. -->
                            still waiting
                        </template>
                    </p>
                </div>
            </PCard>
        </div>
    </div>
</template>
