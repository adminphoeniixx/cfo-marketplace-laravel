<script setup lang="ts">
defineProps<{
    label?: string;
    modelValue?: string | number | null;
    options: { value: string | number | null; label: string }[];
    error?: string;
    helpText?: string;
    placeholder?: string;
    disabled?: boolean;
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
        <div class="relative">
            <select
                :value="modelValue ?? ''"
                :disabled="disabled"
                class="w-full appearance-none rounded-lg border bg-white py-1.5 pr-8 pl-2.5 text-[13px] text-[#303030] outline-none disabled:opacity-60 dark:bg-[#303030] dark:text-[#e3e3e3]"
                :class="
                    error
                        ? 'border-[#e51c00] ring-1 ring-[#e51c00]'
                        : 'border-[#8a8a8a] focus:border-[#005bd3] focus:ring-2 focus:ring-[#005bd3]/40 dark:border-[#616161]'
                "
                @change="
                    $emit(
                        'update:modelValue',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option v-if="placeholder" value="">{{ placeholder }}</option>
                <option
                    v-for="option in options"
                    :key="String(option.value)"
                    :value="option.value ?? ''"
                >
                    {{ option.label }}
                </option>
            </select>
            <svg
                viewBox="0 0 20 20"
                fill="currentColor"
                class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-[#616161] dark:text-[#b5b5b5]"
            >
                <path
                    d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"
                />
            </svg>
        </div>
        <p v-if="error" class="mt-1 text-xs text-[#e51c00]">{{ error }}</p>
        <p
            v-else-if="helpText"
            class="mt-1 text-xs text-[#616161] dark:text-[#b5b5b5]"
        >
            {{ helpText }}
        </p>
    </div>
</template>
