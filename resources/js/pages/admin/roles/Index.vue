<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import { number } from '@/lib/format';

type Role = {
    value: string;
    label: string;
    description: string;
    editable: boolean;
    sections: string[];
    members: number;
};
type Section = { value: string; label: string; group: string };

const props = defineProps<{
    roles: Role[];
    sections: Section[];
}>();

/** Sections laid out the way the sidebar groups them. */
const grouped = computed(() => {
    const groups: { label: string; items: Section[] }[] = [];

    props.sections.forEach((section) => {
        const existing = groups.find((group) => group.label === section.group);

        if (existing) {
            existing.items.push(section);

            return;
        }

        groups.push({ label: section.group, items: [section] });
    });

    return groups;
});

/** Working copy per role, so nothing is saved until Save is pressed. */
const draft = ref<Record<string, string[]>>({});
const saving = ref<string | null>(null);

const reset = () => {
    draft.value = Object.fromEntries(
        props.roles.map((role) => [role.value, [...role.sections]]),
    );
};

reset();
// A save round-trips fresh props; adopt them so the dirty check clears.
watch(() => props.roles, reset);

const has = (role: Role, section: string) =>
    (draft.value[role.value] ?? []).includes(section);

const set = (role: Role, section: string, on: boolean) => {
    const current = draft.value[role.value] ?? [];

    draft.value[role.value] = on
        ? [...current, section]
        : current.filter((value) => value !== section);
};

const isDirty = (role: Role) => {
    const current = [...(draft.value[role.value] ?? [])].sort();
    const saved = [...role.sections].sort();

    return JSON.stringify(current) !== JSON.stringify(saved);
};

const save = (role: Role) => {
    saving.value = role.value;

    router.put(
        `/admin/roles/${role.value}`,
        { sections: draft.value[role.value] ?? [] },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = null),
        },
    );
};

const restoreDefaults = (role: Role) => {
    saving.value = role.value;

    router.post(
        `/admin/roles/${role.value}/reset`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (saving.value = null),
        },
    );
};
</script>

<template>
    <Head title="Roles" />

    <PageHeader
        title="Roles"
        subtitle="Every login carries one role. Tick what that role is allowed to open."
    />

    <div class="space-y-4">
        <PCard v-for="role in roles" :key="role.value">
            <template #default>
                <div
                    class="mb-3 flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-[15px] font-semibold text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{ role.label }}
                            </h2>
                            <PBadge :tone="role.editable ? 'neutral' : 'info'">
                                {{
                                    role.editable
                                        ? `${number(role.members)} member(s)`
                                        : 'Full access'
                                }}
                            </PBadge>
                        </div>
                        <p
                            class="mt-0.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                        >
                            {{ role.description }}
                        </p>
                    </div>

                    <div v-if="role.editable" class="flex shrink-0 gap-1.5">
                        <PButton
                            size="slim"
                            :disabled="saving === role.value"
                            @click="restoreDefaults(role)"
                        >
                            Reset
                        </PButton>
                        <PButton
                            size="slim"
                            variant="primary"
                            :disabled="!isDirty(role)"
                            :loading="saving === role.value"
                            @click="save(role)"
                        >
                            Save
                        </PButton>
                    </div>
                </div>

                <div
                    class="grid gap-x-6 gap-y-4 border-t border-[#f0f0f0] pt-3 sm:grid-cols-2 lg:grid-cols-3 dark:border-[#2a2a2a]"
                >
                    <div v-for="group in grouped" :key="group.label">
                        <p
                            class="mb-1.5 text-[11px] font-semibold tracking-wide text-[#8a8a8a] uppercase"
                        >
                            {{ group.label }}
                        </p>
                        <div class="space-y-1.5">
                            <PCheckbox
                                v-for="section in group.items"
                                :key="section.value"
                                :label="section.label"
                                :model-value="has(role, section.value)"
                                :disabled="!role.editable"
                                @update:model-value="
                                    set(role, section.value, $event)
                                "
                            />
                        </div>
                    </div>
                </div>

                <p v-if="!role.editable" class="mt-3 text-xs text-[#8a8a8a]">
                    The admin role always holds everything — otherwise a bad
                    tick could lock the panel out for good.
                </p>
                <p
                    v-else-if="role.value === 'vendor'"
                    class="mt-3 text-xs text-[#8a8a8a]"
                >
                    Vendor logins are scoped to their own store on top of this,
                    so they only ever see their own orders and products.
                </p>
            </template>
        </PCard>
    </div>
</template>
