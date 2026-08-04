<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import PButton from '@/components/admin/PButton.vue';
import PCard from '@/components/admin/PCard.vue';
import PCheckbox from '@/components/admin/PCheckbox.vue';
import PSelect from '@/components/admin/PSelect.vue';
import PTextarea from '@/components/admin/PTextarea.vue';
import PTextField from '@/components/admin/PTextField.vue';

type Customer = {
    id: number;
    first_name: string;
    last_name: string | null;
    email: string;
    phone: string | null;
    date_of_birth: string | null;
    gender: string | null;
    status: string;
    accepts_marketing: boolean;
    notes: string | null;
    tags: string[] | null;
};

const props = defineProps<{ customer: Customer | null }>();

const isEdit = computed(() => !!props.customer);

const form = useForm<{
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    date_of_birth: string;
    gender: string;
    status: string;
    accepts_marketing: boolean;
    notes: string;
    tags: string[];
}>({
    first_name: props.customer?.first_name ?? '',
    last_name: props.customer?.last_name ?? '',
    email: props.customer?.email ?? '',
    phone: props.customer?.phone ?? '',
    date_of_birth: props.customer?.date_of_birth?.slice(0, 10) ?? '',
    gender: props.customer?.gender ?? '',
    status: props.customer?.status ?? 'active',
    accepts_marketing: props.customer?.accepts_marketing ?? false,
    notes: props.customer?.notes ?? '',
    tags: props.customer?.tags ?? [],
});

const tagInput = ref('');

const addTag = () => {
    const tag = tagInput.value.trim();

    if (tag && !form.tags.includes(tag)) {
        form.tags.push(tag);
    }

    tagInput.value = '';
};

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/customers/${props.customer!.id}`);
    } else {
        form.post('/admin/customers');
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit customer' : 'New customer'" />

    <form @submit.prevent="submit">
        <PageHeader
            :title="
                isEdit
                    ? `${customer!.first_name} ${customer!.last_name ?? ''}`
                    : 'Add customer'
            "
            :back-href="
                isEdit ? `/admin/customers/${customer!.id}` : '/admin/customers'
            "
        >
            <template #actions>
                <PButton href="/admin/customers">Discard</PButton>
                <PButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ isEdit ? 'Save changes' : 'Create customer' }}
                </PButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <PCard title="Customer details">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <PTextField
                            v-model="form.first_name"
                            label="First name"
                            required
                            :error="form.errors.first_name"
                        />
                        <PTextField
                            v-model="form.last_name"
                            label="Last name"
                            :error="form.errors.last_name"
                        />
                        <PTextField
                            v-model="form.email"
                            label="Email"
                            type="email"
                            required
                            :error="form.errors.email"
                        />
                        <PTextField
                            v-model="form.phone"
                            label="Phone"
                            :error="form.errors.phone"
                        />
                        <PTextField
                            v-model="form.date_of_birth"
                            label="Date of birth"
                            type="date"
                            :error="form.errors.date_of_birth"
                        />
                        <PSelect
                            v-model="form.gender"
                            label="Gender"
                            placeholder="Prefer not to say"
                            :options="[
                                { value: 'male', label: 'Male' },
                                { value: 'female', label: 'Female' },
                                { value: 'other', label: 'Other' },
                            ]"
                            :error="form.errors.gender"
                        />
                    </div>
                </PCard>

                <PCard
                    title="Internal notes"
                    subtitle="Only visible to your team"
                >
                    <PTextarea
                        v-model="form.notes"
                        :rows="5"
                        :error="form.errors.notes"
                        placeholder="Preferences, past issues, anything worth remembering…"
                    />
                </PCard>
            </div>

            <div class="space-y-4">
                <PCard title="Status">
                    <PSelect
                        v-model="form.status"
                        :options="[
                            { value: 'active', label: 'Active' },
                            { value: 'blocked', label: 'Blocked' },
                        ]"
                        :error="form.errors.status"
                        help-text="Blocked customers cannot place new orders."
                    />
                </PCard>

                <PCard title="Marketing">
                    <PCheckbox
                        v-model="form.accepts_marketing"
                        label="Subscribed to email marketing"
                        help-text="Customer agreed to receive promotional email."
                    />
                </PCard>

                <PCard title="Tags">
                    <div class="mb-1.5 flex flex-wrap gap-1">
                        <span
                            v-for="tag in form.tags"
                            :key="tag"
                            class="inline-flex items-center gap-1 rounded-md bg-[#f1f1f1] px-2 py-0.5 text-xs text-[#303030] dark:bg-[#303030] dark:text-[#e3e3e3]"
                        >
                            {{ tag }}
                            <button
                                type="button"
                                class="text-[#8a8a8a] hover:text-[#e51c00]"
                                @click="
                                    form.tags = form.tags.filter(
                                        (t) => t !== tag,
                                    )
                                "
                            >
                                ×
                            </button>
                        </span>
                    </div>
                    <PTextField
                        v-model="tagInput"
                        placeholder="VIP, wholesale…"
                        @keydown.enter.prevent="addTag"
                    />
                </PCard>
            </div>
        </div>
    </form>
</template>
