<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import { currency } from '@/lib/format';

type Message = {
    id: number;
    body: string;
    author: string;
    author_type: string;
    created_at: string | null;
};

const props = defineProps<{
    ticket: {
        id: number;
        number: string;
        subject: string;
        status: string;
        category_label: string;
        direction: string;
        from: string;
        can_reply: boolean;
        created_at: string | null;
        order: { id: number; number: string; status: string; grand_total: number } | null;
        messages: Message[];
    };
}>();

const reply = useForm({ body: '', resolve: false });

const send = () =>
    reply.post(`/seller/tickets/${props.ticket.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });

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
    status === 'open' ? 'attention' : status === 'pending' ? 'info' : status === 'resolved' ? 'success' : 'neutral';
</script>

<template>
    <Head :title="`${ticket.number} — ${ticket.subject}`" />

    <PageHeader
        :title="ticket.subject"
        :subtitle="`${ticket.number} · ${ticket.category_label} · ${
            ticket.direction === 'incoming' ? `from ${ticket.from}` : 'to the marketplace'
        }`"
    >
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
                            message.author_type === 'vendor'
                                ? 'border-[#e3e3e3] bg-[#fafafa] dark:border-[#2a2a2a] dark:bg-[#282828]'
                                : 'border-[#e3e3e3] bg-white dark:border-[#2a2a2a] dark:bg-[#232323]'
                        "
                    >
                        <div class="mb-1 flex items-center gap-2 text-xs text-[#8a8a8a]">
                            <strong class="text-[#303030] dark:text-[#e3e3e3]">
                                {{ message.author }}
                            </strong>
                            <span>{{ at(message.created_at) }}</span>
                        </div>
                        <p class="text-[13px] whitespace-pre-line text-[#303030] dark:text-[#e3e3e3]">
                            {{ message.body }}
                        </p>
                    </div>
                </div>
            </PCard>

            <PCard v-if="ticket.can_reply" title="Reply">
                <div class="space-y-3">
                    <PTextarea
                        v-model="reply.body"
                        :rows="5"
                        :error="reply.errors.body"
                        :placeholder="
                            ticket.direction === 'incoming'
                                ? 'What the shopper will read, and be emailed.'
                                : 'What the marketplace will read.'
                        "
                    />
                    <PCheckbox
                        v-if="ticket.direction === 'incoming'"
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
                            Send reply
                        </PButton>
                    </div>
                </div>
            </PCard>
        </div>

        <div class="space-y-4">
            <PCard v-if="ticket.order" title="About this order">
                <div class="space-y-1 text-[13px]">
                    <p class="font-medium">{{ ticket.order.number }}</p>
                    <p class="text-[#8a8a8a]">
                        {{ ticket.order.status }} · {{ currency(ticket.order.grand_total) }}
                    </p>
                    <a class="inline-block pt-1 underline" :href="`/seller/orders/${ticket.order.id}`">
                        Open order
                    </a>
                </div>
            </PCard>

            <PCard title="Opened">
                <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    {{ at(ticket.created_at) }}
                </p>
            </PCard>
        </div>
    </div>
</template>
