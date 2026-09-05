<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { number } from '@/lib/format';

type Ticket = {
    id: number;
    number: string;
    subject: string;
    status: string;
    category_label: string;
    direction: string;
    from: string;
    order_number: string | null;
    waiting_hours: number | null;
};

const props = defineProps<{
    tickets: {
        data: Ticket[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { direction: string; status?: string };
    categories: Record<string, string>;
    statuses: string[];
    summary: { incoming_open: number; outgoing_open: number };
}>();

const setDirection = (direction: string) =>
    router.get('/seller/tickets', { direction }, { preserveState: true, replace: true });

const askOpen = ref(false);

const ask = useForm({ subject: '', message: '', category: 'other' });

const send = () =>
    ask.post('/seller/tickets', { onSuccess: () => (askOpen.value = false) });

const waited = (hours: number | null) =>
    hours === null ? '—' : hours < 24 ? `${Math.round(hours)}h` : `${Math.round(hours / 24)}d`;

const tone = (status: string) =>
    status === 'open' ? 'attention' : status === 'pending' ? 'info' : status === 'resolved' ? 'success' : 'neutral';
</script>

<template>
    <Head title="Support" />

    <PageHeader
        title="Support"
        subtitle="Shoppers writing to your store, and your store writing to the marketplace"
    >
        <template #actions>
            <PButton variant="primary" @click="askOpen = true">
                Ask the marketplace
            </PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <MetricCard
            label="Waiting on you"
            :value="number(summary.incoming_open)"
            caption="shoppers who have written in"
        />
        <MetricCard
            label="With the marketplace"
            :value="number(summary.outgoing_open)"
            caption="things you have asked about"
        />
    </div>

    <PCard :padding="false">
        <div
            class="flex flex-wrap items-center gap-1 border-b border-[#e3e3e3] px-2 py-2 dark:border-[#3a3a3a]"
        >
            <button
                v-for="tab in [
                    { value: 'incoming', label: 'From shoppers' },
                    { value: 'outgoing', label: 'To the marketplace' },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-lg px-2.5 py-1 text-[13px] font-medium transition"
                :class="
                    filters.direction === tab.value
                        ? 'bg-[#f1f1f1] text-[#303030] dark:bg-[#303030] dark:text-white'
                        : 'text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#303030]'
                "
                @click="setDirection(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <PTable
            v-if="tickets.data.length"
            :headers="['Ticket', 'From', 'About', 'Waiting', 'Status']"
            :align-right="[3, 4]"
        >
            <tr
                v-for="ticket in tickets.data"
                :key="ticket.id"
                class="cursor-pointer border-b border-[#f0f0f0] last:border-0 hover:bg-[#fafafa] dark:border-[#2a2a2a] dark:hover:bg-[#282828]"
                @click="router.get(`/seller/tickets/${ticket.id}`)"
            >
                <td class="px-4 py-2.5">
                    <p class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]">
                        {{ ticket.subject }}
                    </p>
                    <p class="text-xs text-[#8a8a8a]">
                        {{ ticket.number }}
                        <template v-if="ticket.order_number"> · {{ ticket.order_number }}</template>
                    </p>
                </td>
                <td class="px-4 py-2.5 text-[13px]">{{ ticket.from }}</td>
                <td class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    {{ ticket.category_label }}
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                    {{ waited(ticket.waiting_hours) }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="tone(ticket.status)">{{ ticket.status }}</PBadge>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            :title="filters.direction === 'incoming' ? 'Nobody is waiting' : 'You have not asked anything'"
            :description="
                filters.direction === 'incoming'
                    ? 'Shoppers who write to your store land here.'
                    : 'Payout not landed, a listing taken down — ask the marketplace and it is written down.'
            "
        />

        <PPagination
            :links="tickets.links"
            :from="tickets.from"
            :to="tickets.to"
            :total="tickets.total"
        />
    </PCard>

    <PModal
        :open="askOpen"
        title="Ask the marketplace"
        size="small"
        @close="askOpen = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="ask.subject"
                label="Subject"
                required
                placeholder="Payout for August has not landed"
                :error="ask.errors.subject"
            />
            <PSelect
                v-model="ask.category"
                label="About"
                :options="Object.entries(categories).map(([value, label]) => ({ value, label }))"
                :error="ask.errors.category"
            />
            <PTextarea
                v-model="ask.message"
                label="Message"
                required
                :rows="5"
                :error="ask.errors.message"
            />
        </div>

        <template #footer>
            <PButton @click="askOpen = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="ask.processing"
                :disabled="!ask.subject.trim() || !ask.message.trim()"
                @click="send"
            >
                Send
            </PButton>
        </template>
    </PModal>
</template>
