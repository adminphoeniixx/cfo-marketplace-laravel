<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextField from '@/components/admin/PTextField.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import { date, number } from '@/lib/format';

type Broadcast = {
    id: number;
    heading: string;
    message: string;
    link: string | null;
    recipients: number;
    sender: string | null;
    created_at: string | null;
};

const props = defineProps<{
    broadcasts: {
        data: Broadcast[];
        links: { url: string | null; label: string; active: boolean }[];
        meta?: unknown;
        from: number | null;
        to: number | null;
        total: number;
    };
    reach: number;
}>();

const form = useForm({ heading: '', message: '', link: '' });

const submit = () =>
    form.post('/admin/broadcasts', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });

/*
| What the phone will actually draw.
|
| A lock screen is not a page: Android truncates a title around 40 characters
| and a body around 100 before the shopper expands it, and most never expand
| it. Showing the sender their own words in that shape is the difference
| between writing a notification and writing a paragraph that gets cut.
*/
const preview = computed(() => ({
    heading: form.heading || 'Your heading',
    message: form.message || 'The line a shopper reads on their lock screen.',
}));
</script>

<template>
    <Head title="Broadcasts" />

    <PageHeader
        title="Broadcasts"
        subtitle="Write to every shopper who asked to hear about deals"
    />

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-4">
            <PCard
                title="New broadcast"
                :subtitle="`Reaches ${number(props.reach)} shopper${props.reach === 1 ? '' : 's'} who have deals switched on`"
            >
                <form class="space-y-3" @submit.prevent="submit">
                    <PTextField
                        v-model="form.heading"
                        label="Heading"
                        placeholder="Weekend on the house"
                        required
                        :error="form.errors.heading"
                    />
                    <PTextarea
                        v-model="form.message"
                        label="Message"
                        :rows="3"
                        placeholder="Free delivery on everything until Sunday night."
                        required
                        :error="form.errors.message"
                    />
                    <PTextField
                        v-model="form.link"
                        label="Opens in the app (optional)"
                        placeholder="/products?filter=deals"
                        :error="form.errors.link"
                    />
                    <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                        A route inside the app, starting with a slash — not a
                        web address. Leave it empty to open the notification
                        list.
                    </p>

                    <div class="flex items-center gap-3 pt-1">
                        <PButton
                            type="submit"
                            variant="primary"
                            :loading="form.processing"
                            :disabled="form.processing || props.reach === 0"
                        >
                            Send to {{ number(props.reach) }}
                        </PButton>
                        <span
                            v-if="props.reach === 0"
                            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            Nobody has deals switched on yet, so there is
                            nowhere to send this.
                        </span>
                    </div>
                </form>
            </PCard>

            <PCard title="Sent" subtitle="Every broadcast, and who sent it">
                <PTable
                    v-if="props.broadcasts.data.length"
                    :headers="['Message', 'Sent to', 'By', 'When']"
                >
                    <tr
                        v-for="b in props.broadcasts.data"
                        :key="b.id"
                        class="hover:bg-[#fafafa] dark:hover:bg-[#232323]"
                    >
                        <td class="px-4 py-2.5">
                            <p
                                class="text-[13px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{ b.heading }}
                            </p>
                            <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                                {{ b.message }}
                            </p>
                            <p v-if="b.link" class="text-xs text-[#8a8a8a]">
                                {{ b.link }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-2.5 text-right text-[13px] tabular-nums"
                        >
                            {{ number(b.recipients) }}
                        </td>
                        <td class="px-4 py-2.5 text-[13px]">
                            {{ b.sender ?? '—' }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{ b.created_at ? date(b.created_at) : '—' }}
                        </td>
                    </tr>
                </PTable>

                <PEmptyState
                    v-else
                    title="Nothing sent yet"
                    description="Broadcasts you send appear here, with the number of shoppers each reached."
                />

                <PPagination
                    v-if="props.broadcasts.data.length"
                    :links="props.broadcasts.links"
                    :from="props.broadcasts.from"
                    :to="props.broadcasts.to"
                    :total="props.broadcasts.total"
                />
            </PCard>
        </div>

        <!--
        | The lock screen, roughly.
        |
        | Not a pixel-accurate phone: the point is only to show how much of the
        | message survives, because that is the decision the sender is making.
        -->
        <PCard title="On a phone">
            <div
                class="rounded-2xl bg-[#2b2b2f] p-3 text-white shadow-inner dark:bg-[#141416]"
            >
                <div class="rounded-xl bg-white/12 p-3 backdrop-blur">
                    <p class="text-[11px] tracking-wide text-white/60 uppercase">
                        CFO
                    </p>
                    <p class="mt-0.5 line-clamp-1 text-[13px] font-semibold">
                        {{ preview.heading }}
                    </p>
                    <p class="mt-0.5 line-clamp-2 text-[12.5px] text-white/80">
                        {{ preview.message }}
                    </p>
                </div>
            </div>
            <p class="mt-3 text-xs text-[#616161] dark:text-[#b5b5b5]">
                Shoppers who switched deals off never see this — not on the
                phone and not in the app's notification list. Order updates and
                refund decisions are separate and are never suppressed by it.
            </p>
        </PCard>
    </div>
</template>
