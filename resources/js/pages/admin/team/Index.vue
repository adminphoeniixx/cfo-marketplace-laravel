<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PFilters from '@/components/admin/PFilters.vue';
import PModal from '@/components/admin/PModal.vue';
import PPagination from '@/components/admin/PPagination.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { number } from '@/lib/format';

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: string;
    role_label: string;
    vendor_id: number | null;
    vendor_name: string | null;
    is_active: boolean;
    is_self: boolean;
};

const props = defineProps<{
    members: {
        data: Member[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    roles: { value: string; label: string }[];
    vendors: { id: number; name: string }[];
    canManageAdmins: boolean;
    counts: { all: number; active: number; inactive: number };
}>();

const show = ref(false);
const editing = ref<Member | null>(null);

const form = useForm({
    name: '',
    email: '',
    role: 'staff',
    vendor_id: '' as string | number,
    phone: '',
    is_active: true,
    password: '',
    password_confirmation: '',
});

// A manager can hold team access, but must not be able to mint admins.
const assignableRoles = computed(() =>
    props.canManageAdmins
        ? props.roles
        : props.roles.filter((role) => role.value !== 'admin'),
);

const vendorOptions = computed(() =>
    props.vendors.map((vendor) => ({ value: vendor.id, label: vendor.name })),
);

const open = (member: Member | null) => {
    editing.value = member;
    form.clearErrors();

    Object.assign(form, {
        name: member?.name ?? '',
        email: member?.email ?? '',
        role: member?.role ?? 'staff',
        vendor_id: member?.vendor_id ?? '',
        phone: member?.phone ?? '',
        is_active: member?.is_active ?? true,
        password: '',
        password_confirmation: '',
    });

    show.value = true;
};

const submit = () => {
    const onSuccess = () => (show.value = false);

    if (editing.value) {
        form.put(`/admin/team/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });

        return;
    }

    form.post('/admin/team', { preserveScroll: true, onSuccess });
};

const toggle = (member: Member) =>
    router.patch(
        `/admin/team/${member.id}/toggle`,
        {},
        { preserveScroll: true },
    );

const destroy = (member: Member) =>
    router.delete(`/admin/team/${member.id}`, { preserveScroll: true });

const initials = (member: Member) =>
    member.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();

const roleTone = (role: string) =>
    role === 'admin' ? 'info' : role === 'vendor' ? 'attention' : 'neutral';

// Admin rows are off limits unless you are one yourself.
const locked = (member: Member) =>
    member.role === 'admin' && !props.canManageAdmins;
</script>

<template>
    <Head title="Team" />

    <PageHeader
        title="Team"
        :subtitle="`${number(members.total)} member(s) with a panel login`"
    >
        <template #actions>
            <PButton variant="primary" @click="open(null)">
                Add member
            </PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <MetricCard label="Members" :value="number(counts.all)" />
        <MetricCard label="Active" :value="number(counts.active)" />
        <MetricCard
            label="Deactivated"
            :value="number(counts.inactive)"
            caption="cannot sign in"
        />
    </div>

    <PCard :padding="false">
        <PFilters
            route-url="/admin/team"
            :filters="filters"
            search-placeholder="Search by name, email or phone"
            :tabs="[
                { value: '', label: 'All', count: counts.all },
                { value: 'active', label: 'Active', count: counts.active },
                {
                    value: 'inactive',
                    label: 'Deactivated',
                    count: counts.inactive,
                },
            ]"
        />

        <PTable
            v-if="members.data.length"
            :headers="['Member', 'Role', 'Store', 'Status', '']"
            :align-right="[3, 4]"
        >
            <tr
                v-for="member in members.data"
                :key="member.id"
                class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
            >
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#e3e3e3] text-[11px] font-semibold text-[#616161] dark:bg-[#3a3a3a] dark:text-[#b5b5b5]"
                        >
                            {{ initials(member) }}
                        </span>
                        <div class="min-w-0">
                            <p
                                class="truncate text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                            >
                                {{ member.name }}
                                <span
                                    v-if="member.is_self"
                                    class="text-[#8a8a8a]"
                                >
                                    · you
                                </span>
                            </p>
                            <p class="truncate text-xs text-[#8a8a8a]">
                                {{ member.email }}
                            </p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-2.5">
                    <PBadge :tone="roleTone(member.role)">
                        {{ member.role_label }}
                    </PBadge>
                </td>
                <td
                    class="px-4 py-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    {{ member.vendor_name ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="member.is_active ? 'success' : 'critical'">
                        {{ member.is_active ? 'Active' : 'Deactivated' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex justify-end gap-1.5">
                        <PButton
                            size="slim"
                            :disabled="locked(member)"
                            @click="open(member)"
                        >
                            Edit
                        </PButton>
                        <PButton
                            size="slim"
                            :disabled="locked(member) || member.is_self"
                            @click="toggle(member)"
                        >
                            {{ member.is_active ? 'Deactivate' : 'Activate' }}
                        </PButton>
                        <PButton
                            size="slim"
                            variant="critical"
                            :disabled="locked(member) || member.is_self"
                            @click="destroy(member)"
                        >
                            Delete
                        </PButton>
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No team members"
            description="Add the people who should be able to sign in to this panel."
        />

        <PPagination
            :links="members.links"
            :from="members.from"
            :to="members.to"
            :total="members.total"
        />
    </PCard>

    <PModal
        :open="show"
        :title="editing ? `Edit ${editing.name}` : 'Add team member'"
        size="small"
        @close="show = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="form.name"
                label="Name"
                required
                :error="form.errors.name"
            />
            <PTextField
                v-model="form.email"
                label="Email"
                type="email"
                required
                :error="form.errors.email"
                help-text="This is what they sign in with."
            />
            <PTextField
                v-model="form.phone"
                label="Phone"
                :error="form.errors.phone"
            />
            <PSelect
                v-model="form.role"
                label="Role"
                :options="assignableRoles"
                :error="form.errors.role"
                help-text="What the role can open is set on the Roles screen."
            />
            <PSelect
                v-if="form.role === 'vendor'"
                v-model="form.vendor_id"
                label="Store"
                placeholder="Pick a store…"
                :options="vendorOptions"
                :error="form.errors.vendor_id"
                help-text="A vendor login only ever sees this store's data."
            />
            <PTextField
                v-model="form.password"
                label="Password"
                type="password"
                :required="!editing"
                :error="form.errors.password"
                :help-text="
                    editing
                        ? 'Leave blank to keep the current password.'
                        : 'At least 8 characters.'
                "
            />
            <PTextField
                v-if="form.password"
                v-model="form.password_confirmation"
                label="Confirm password"
                type="password"
            />
            <PCheckbox
                v-model="form.is_active"
                label="Can sign in"
                help-text="Deactivated members are signed out and blocked at the door."
            />
        </div>

        <template #footer>
            <PButton @click="show = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="form.processing"
                @click="submit"
            >
                {{ editing ? 'Save' : 'Add member' }}
            </PButton>
        </template>
    </PModal>
</template>
