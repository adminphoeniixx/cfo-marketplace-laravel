<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PPagination from '@/components/admin/PPagination.vue';
import { useWebPush } from '@/composables/useWebPush';
import { number } from '@/lib/format';

type Row = {
    id: string;
    title: string;
    body: string;
    url: string;
    tone: string;
    kind: string;
    read: boolean;
    created_at: string;
};

const props = defineProps<{
    /** Panel prefix. The seller panel renders this same page under /seller. */
    base?: string;
    notifications: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    counts: { all: number; unread: number };
    kinds: { value: string; label: string; count: number }[];
    push: { enabled: boolean; publicKey: string | null; devices: number };
}>();

// Fixed for the life of the page, so a plain const rather than a computed.
const base = props.base ?? '/admin';

const {
    supported,
    permission,
    subscribed,
    busy,
    error: pushError,
    subscribe,
    unsubscribe,
} = useWebPush(props.push.publicKey);

const activeFilter = computed(() => props.filters.filter ?? '');
const activeKind = computed(() => props.filters.kind ?? '');

/** Read/unread and kind narrow together, so both survive a click on either. */
const go = (params: { filter?: string; kind?: string }) =>
    router.get(
        `${base}/notifications`,
        Object.fromEntries(
            Object.entries({
                filter: activeFilter.value,
                kind: activeKind.value,
                ...params,
            }).filter(([, value]) => value),
        ),
        { preserveScroll: true, preserveState: true, replace: true },
    );

const setFilter = (filter: string) => go({ filter });
const setKind = (kind: string) => go({ kind });

const open = (row: Row) => {
    if (!row.read) {
        router.post(
            `${base}/notifications/${row.id}/read`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    router.visit(row.url);
};

const markRead = (row: Row) =>
    router.post(
        `${base}/notifications/${row.id}/read`,
        {},
        { preserveScroll: true },
    );

const markAllRead = () =>
    router.post(`${base}/notifications/read-all`, {}, { preserveScroll: true });

const destroy = (row: Row) =>
    router.delete(`${base}/notifications/${row.id}`, { preserveScroll: true });

const clearRead = () =>
    router.delete(`${base}/notifications/read`, { preserveScroll: true });

const when = (iso: string) => {
    const then = new Date(iso).getTime();
    const minutes = Math.round((Date.now() - then) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    if (minutes < 1440) {
        return `${Math.round(minutes / 60)}h ago`;
    }

    if (minutes < 10080) {
        return `${Math.round(minutes / 1440)}d ago`;
    }

    return new Date(iso).toLocaleDateString();
};

const pushLabel = computed(() => {
    if (!props.push.enabled) {
        return 'Not configured on this server';
    }

    if (!supported.value) {
        return 'This browser cannot receive push';
    }

    if (permission.value === 'denied') {
        return 'Blocked in your browser settings';
    }

    return subscribed.value ? 'On for this browser' : 'Off for this browser';
});
</script>

<template>
    <Head title="Notifications" />

    <PageHeader
        title="Notifications"
        :subtitle="
            counts.unread
                ? `${number(counts.unread)} unread of ${number(counts.all)}`
                : `${number(counts.all)} notification(s), all read`
        "
    >
        <template #actions>
            <PButton :disabled="!counts.unread" @click="markAllRead">
                Mark all read
            </PButton>
            <PButton
                :disabled="counts.all === counts.unread"
                @click="clearRead"
            >
                Clear read
            </PButton>
        </template>
    </PageHeader>

    <PCard class="mb-4" title="Browser notifications">
        <template #actions>
            <PBadge :tone="subscribed ? 'success' : 'neutral'">
                {{ pushLabel }}
            </PBadge>
        </template>

        <p class="text-[13px] text-[#616161] dark:text-[#b5b5b5]">
            Turn this on to be told about new orders and requests even when the
            panel is closed. It applies to this browser on this device only —
            switch it on again anywhere else you sign in.
        </p>

        <p v-if="pushError" class="mt-2 text-[13px] text-[#e51c00]">
            {{ pushError }}
        </p>

        <p v-else-if="!push.enabled" class="mt-2 text-[13px] text-[#8a8a8a]">
            An admin still has to run
            <code class="font-mono">php artisan webpush:vapid</code> and put the
            keys in the environment.
        </p>

        <div
            v-if="push.enabled && supported"
            class="mt-3 flex items-center gap-2"
        >
            <PButton
                v-if="!subscribed"
                variant="primary"
                :loading="busy"
                :disabled="permission === 'denied'"
                @click="subscribe"
            >
                Turn on
            </PButton>
            <PButton v-else :loading="busy" @click="unsubscribe">
                Turn off
            </PButton>
            <span v-if="push.devices" class="text-xs text-[#8a8a8a]">
                {{ number(push.devices) }} device(s) subscribed on your account
            </span>
        </div>
    </PCard>

    <PCard :padding="false">
        <div
            class="flex flex-wrap items-center gap-1 border-b border-[#e3e3e3] px-2 py-2 dark:border-[#3a3a3a]"
        >
            <button
                v-for="tab in [
                    { value: '', label: 'All', count: counts.all },
                    { value: 'unread', label: 'Unread', count: counts.unread },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-lg px-2.5 py-1 text-[13px] font-medium transition"
                :class="
                    activeFilter === tab.value
                        ? 'bg-[#f1f1f1] text-[#303030] dark:bg-[#303030] dark:text-white'
                        : 'text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#303030]'
                "
                @click="setFilter(tab.value)"
            >
                {{ tab.label }}
                <span class="text-[#8a8a8a]">{{ tab.count }}</span>
            </button>

            <!--
            | What kind of news, from the kinds this person has actually been
            | sent. The server has filtered on this since the beginning; there
            | was simply no way to ask for it.
            -->
            <template v-if="kinds.length > 1">
                <span class="mx-1 h-4 w-px bg-[#e3e3e3] dark:bg-[#3a3a3a]" />
                <button
                    v-for="kind in [{ value: '', label: 'Everything', count: counts.all }, ...kinds]"
                    :key="kind.value"
                    type="button"
                    class="rounded-lg px-2.5 py-1 text-[13px] transition"
                    :class="
                        activeKind === kind.value
                            ? 'bg-[#f1f1f1] font-medium text-[#303030] dark:bg-[#303030] dark:text-white'
                            : 'text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#303030]'
                    "
                    @click="setKind(kind.value)"
                >
                    {{ kind.label }}
                    <span class="text-[#8a8a8a]">{{ kind.count }}</span>
                </button>
            </template>
        </div>

        <ul v-if="notifications.data.length">
            <li
                v-for="row in notifications.data"
                :key="row.id"
                class="flex items-start gap-3 border-b border-[#f0f0f0] px-4 py-3 last:border-0 dark:border-[#2a2a2a]"
                :class="row.read ? '' : 'bg-[#f7fbff] dark:bg-[#12233a]'"
            >
                <button
                    type="button"
                    class="min-w-0 flex-1 text-left"
                    @click="open(row)"
                >
                    <p
                        class="text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                        :class="row.read ? '' : 'font-semibold'"
                    >
                        {{ row.title }}
                    </p>
                    <p class="text-xs text-[#616161] dark:text-[#b5b5b5]">
                        {{ row.body }}
                    </p>
                </button>

                <div class="flex shrink-0 items-center gap-2">
                    <span class="text-xs whitespace-nowrap text-[#8a8a8a]">
                        {{ when(row.created_at) }}
                    </span>
                    <PButton
                        v-if="!row.read"
                        size="slim"
                        @click="markRead(row)"
                    >
                        Mark read
                    </PButton>
                    <PButton
                        size="slim"
                        variant="critical"
                        @click="destroy(row)"
                    >
                        Delete
                    </PButton>
                </div>
            </li>
        </ul>

        <PEmptyState
            v-else
            title="Nothing here"
            :description="
                activeFilter === 'unread'
                    ? 'You have read everything.'
                    : 'New orders, cancellations, refunds and team changes will show up here.'
            "
        />

        <PPagination
            :links="notifications.links"
            :from="notifications.from"
            :to="notifications.to"
            :total="notifications.total"
        />
    </PCard>
</template>
