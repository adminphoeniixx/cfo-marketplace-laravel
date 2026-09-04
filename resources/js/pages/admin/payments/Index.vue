<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PBadge from '@/components/admin/PBadge.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PEmptyState from '@/components/admin/PEmptyState.vue';
import PModal from '@/components/admin/PModal.vue';
import PTable from '@/components/admin/PTable.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';
import { compactCurrency, currency, number } from '@/lib/format';

type Method = {
    id: number;
    name: string;
    code: string;
    description: string | null;
    icon: string | null;
    glyph: string;
    is_active: boolean;
    position: number;
    orders_count: number;
    revenue: number;
};

defineProps<{
    methods: Method[];
    unlisted: string[];
    summary: {
        total: number;
        active: number;
        orders: number;
        revenue: number;
    };
}>();

const show = ref(false);
const editing = ref<Method | null>(null);

const form = useForm({
    name: '',
    description: '',
    icon: '',
    is_active: true,
    position: 0,
});

const open = (method: Method | null) => {
    editing.value = method;
    form.clearErrors();

    Object.assign(form, {
        name: method?.name ?? '',
        description: method?.description ?? '',
        icon: method?.icon ?? '',
        is_active: method?.is_active ?? true,
        position: method?.position ?? 0,
    });

    show.value = true;
};

const submit = () => {
    const onSuccess = () => (show.value = false);

    if (editing.value) {
        form.put(`/admin/payments/${editing.value.id}`, {
            preserveScroll: true,
            onSuccess,
        });

        return;
    }

    form.post('/admin/payments', { preserveScroll: true, onSuccess });
};

const toggle = (method: Method) =>
    router.patch(
        `/admin/payments/${method.id}/toggle`,
        {},
        { preserveScroll: true },
    );

const destroy = (method: Method) =>
    router.delete(`/admin/payments/${method.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Payment methods" />

    <PageHeader
        title="Payment methods"
        subtitle="What buyers can pay with, and what staff can pick when raising an order"
    >
        <template #actions>
            <PButton variant="primary" @click="open(null)">
                Add method
            </PButton>
        </template>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <MetricCard label="Methods" :value="number(summary.total)" />
        <MetricCard label="Active" :value="number(summary.active)" />
        <MetricCard label="Orders paid" :value="number(summary.orders)" />
        <MetricCard
            label="Revenue"
            :value="compactCurrency(summary.revenue)"
            caption="excludes cancelled"
        />
    </div>

    <PCard
        v-if="unlisted.length"
        title="Not in your list"
        subtitle="Orders use these values, but they are not configured as methods"
        class="mb-4"
    >
        <div class="flex flex-wrap gap-1.5">
            <PBadge v-for="name in unlisted" :key="name" tone="warning">
                {{ name }}
            </PBadge>
        </div>
    </PCard>

    <PCard :padding="false">
        <PTable
            v-if="methods.length"
            :headers="['Method', 'Status', 'Orders', 'Revenue', 'Order', '']"
            :align-right="[2, 3, 4, 5]"
        >
            <tr
                v-for="method in methods"
                :key="method.id"
                class="border-b border-[#f0f0f0] last:border-0 dark:border-[#2a2a2a]"
            >
                <td class="px-4 py-2.5">
                    <p
                        class="text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
                    >
                        <span class="mr-1">{{ method.glyph }}</span>
                        {{ method.name }}
                    </p>
                    <p v-if="method.description" class="text-xs text-[#8a8a8a]">
                        {{ method.description }}
                    </p>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <PBadge :tone="method.is_active ? 'success' : 'neutral'">
                        {{ method.is_active ? 'Active' : 'Hidden' }}
                    </PBadge>
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                    {{ number(method.orders_count) }}
                </td>
                <td class="px-4 py-2.5 text-right text-[13px] tabular-nums">
                    {{ currency(method.revenue) }}
                </td>
                <td
                    class="px-4 py-2.5 text-right text-[13px] text-[#8a8a8a] tabular-nums"
                >
                    {{ method.position }}
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex justify-end gap-1.5">
                        <PButton size="slim" @click="open(method)">
                            Edit
                        </PButton>
                        <PButton size="slim" @click="toggle(method)">
                            {{ method.is_active ? 'Hide' : 'Activate' }}
                        </PButton>
                        <PButton
                            size="slim"
                            variant="critical"
                            :disabled="method.orders_count > 0"
                            @click="destroy(method)"
                        >
                            Delete
                        </PButton>
                    </div>
                </td>
            </tr>
        </PTable>

        <PEmptyState
            v-else
            title="No payment methods"
            description="Add the ways your buyers pay so staff can pick one on an order."
        />
    </PCard>

    <PModal
        :open="show"
        :title="editing ? 'Edit payment method' : 'Add payment method'"
        size="small"
        @close="show = false"
    >
        <div class="space-y-3">
            <PTextField
                v-model="form.name"
                label="Name"
                required
                placeholder="UPI, Cash on Delivery…"
                :error="form.errors.name"
                help-text="Shown on orders. Renaming updates existing orders too."
            />
            <PTextarea
                v-model="form.description"
                label="Description"
                :rows="2"
                :error="form.errors.description"
                placeholder="Anything staff should know about this method"
            />
            <PTextField
                v-model="form.icon"
                label="Icon"
                placeholder="⚡"
                :error="form.errors.icon"
                help-text="One emoji, drawn beside this method in the shopper app. Leave blank to derive one."
            />
            <PTextField
                v-model="form.position"
                label="Sort order"
                type="number"
                min="0"
                :error="form.errors.position"
                help-text="Lower numbers appear first."
            />
            <PCheckbox
                v-model="form.is_active"
                label="Available for new orders"
                help-text="Hidden methods stay on past orders but cannot be picked."
            />
        </div>

        <template #footer>
            <PButton @click="show = false">Cancel</PButton>
            <PButton
                variant="primary"
                :loading="form.processing"
                @click="submit"
            >
                {{ editing ? 'Save' : 'Add method' }}
            </PButton>
        </template>
    </PModal>
</template>
