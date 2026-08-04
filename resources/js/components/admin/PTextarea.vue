<script setup lang="ts">
defineProps<{
    label?: string;
    modelValue?: string | null;
    placeholder?: string;
    error?: string;
    helpText?: string;
    rows?: number;
}>();

defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div>
        <label
            v-if="label"
            class="mb-1 block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ label }}
        </label>
        <textarea
            :value="modelValue ?? ''"
            :placeholder="placeholder"
            :rows="rows || 4"
            class="w-full rounded-lg border bg-white px-2.5 py-1.5 text-[13px] text-[#303030] outline-none placeholder:text-[#8a8a8a] dark:bg-[#303030] dark:text-[#e3e3e3]"
            :class="
                error
                    ? 'border-[#e51c00] ring-1 ring-[#e51c00]'
                    : 'border-[#8a8a8a] focus:border-[#005bd3] focus:ring-2 focus:ring-[#005bd3]/40 dark:border-[#616161]'
            "
            @input="
                $emit(
                    'update:modelValue',
                    ($event.target as HTMLTextAreaElement).value,
                )
            "
        />
        <p v-if="error" class="mt-1 text-xs text-[#e51c00]">{{ error }}</p>
        <p
            v-else-if="helpText"
            class="mt-1 text-xs text-[#616161] dark:text-[#b5b5b5]"
        >
            {{ helpText }}
        </p>
    </div>
</template>
