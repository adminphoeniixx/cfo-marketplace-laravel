<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { number } from '@/lib/format';

type Ticket = {
    id: number;
    number: string;
    subject: string;
    status: string;
    priority: string;
    category_label: string;
    customer: { id: number; name: string; email: string | null } | null;
    order_number: string | null;
    assignee: { id: number; name: string } | null;
    waiting_hours: number | null;
    last_reply_at: string | null;
};

const props = defineProps<{
    tickets: {
        data: Ticket[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search?: string; status?: string; category?: string; assigned?: string };
    categories: Record<string, string>;
    statuses: string[];
    summary: {
        open: number;
        pending: number;
        unassigned: number;
        oldest_waiting_hours: number | null;
    };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const category = ref(props.filters.category ?? '');
const assigned = ref(props.filters.assigned ?? '');

let timer: ReturnType<typeof setTimeout> | undefined;

const apply = () => {
    router.get(
        '/admin/tickets',
        {
            search: search.value || undefined,
            status: status.value || undefined,
            category: category.value || undefined,
            assigned: assigned.value || undefined,
        },
        { preserveState: true, replace: true },
    );
};

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 350);
});

watch([status, category, assigned], apply);

const tone = (ticket: Ticket) => {
    if (ticket.status === 'open') {
        return ticket.priority === 'urgent' || (ticket.waiting_hours ?? 0) > 24
            ? 'critical'
            : 'attention';
    }

    return ticket.status === 'pending' ? 'info' : 'success';
};

/** "3h", "2d" — how long the person at this row has been waiting. */
const waited = (hours: number | null) => {
    if (hours === null) {
        return '—';
    }

    return hours < 24
        ? `${Math.round(hours)}h`
        : `${Math.round(hours / 24)}d`;
};
</script>

<template>
    <Head title="Support" />

    <PageHeader
        title="Support"
        subtitle="Answered oldest first — the person who has waited longest is the next one to hear back"
    />

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <MetricCard label="Waiting on us" :value="number(summary.open)" />
        <MetricCard label="Waiting on the shopper" :value="number(summary.pending)" />
        <MetricCard label="Nobody assigned" :value="number(summary.unassigned)" />
        <MetricCard
            label="Longest wait"
            :value="summary.oldest_waiting_hours === null ? '—' : waited(summary.oldest_waiting_hours)"
            caption="on an open ticket"
        />
    </div>

    <PCard class="mb-4">
        <div class="grid gap-3 sm:grid-cols-4">
            <PTextField v-model="search" label="Search" placeholder="Number or subject" />
            <PSelect
                v-model="status"
                label="Status"
                :options="[
                    { value: '', label: 'Any status' },
                    ...statuses.map((s) => ({ value: s, label: s })),
                ]"
            />
            <PSelect
                v-model="category"
                label="About"
                :options="[
                    { value: '', label: 'Anything' },
                    ...Object.entries(categories).map(([value, label]) => ({ value, label })),
                ]"
            />
            <PSelect
                v-model="assigned"
                label="Assigned"
                :options="[
                    { value: '', label: 'Anyone' },
                    { value: 'me', label: 'Me' },
                    { value: 'nobody', label: 'Nobody' },
                ]"
            />
        </div>
    </PCard>

    <PCard :padding="false">
        <PTable
            v-if="tickets.data.length"
            :headers="['Ticket', 'Shopper', 'About', 'Assigned', 'Waiting', 'Status']"
            :align-right="[4, 5]"
        >
            <tr
                v-for="ticket in tickets.data"
                :key="ticket.id"
                class="cursor-pointer border-b border-[#f0f0f0] last:border-0 hover:bg-[#fafafa] dark:border-[#2a2a2a] dark:hover:bg-[#282828]"
                @click="router.get(`/admin/tickets/${ticket.id}`)"
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
                <td class="px-4 py-2.5">
                    <p class="text-[13px]">{{ ticket.customer?.name ?? '—' }}</p>
                    <p class="text-xs text-[#8a8a8a]">{{ ticket.customer?.email }}</p>
                </td>
                <td class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    {{ ticket.category_label }}
                </td>
                <td class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]">
                    {{ ticket.assignee?.name ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                    {{ waited(ticket.waiting_hours) }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="tone(ticket)">{{ ticket.status }}</PBadge>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="Nothing waiting"
            description="No ticket matches these filters. An empty support queue is the good kind of empty."
        />

        <PPagination
            :links="tickets.links"
            :from="tickets.from"
            :to="tickets.to"
            :total="tickets.total"
        />
    </PCard>
</template>
