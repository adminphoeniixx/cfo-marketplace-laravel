<script setup lang="ts">
defineProps<{
    label?: string;
    modelValue?: string | number | null;
    type?: string;
    placeholder?: string;
    error?: string;
    helpText?: string;
    prefix?: string;
    suffix?: string;
    disabled?: boolean;
    required?: boolean;
    min?: string | number;
    step?: string | number;
}>();

defineEmits<{ 'update:modelValue': [value: string | number] }>();
</script>

<template>
    <div>
        <label
            v-if="label"
            class="mb-1 block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ label }}
            <span v-if="required" class="text-[#e51c00]">*</span>
        </label>
        <div
            class="flex items-center rounded-lg border bg-white transition dark:bg-[#303030]"
            :class="
                error
                    ? 'border-[#e51c00] ring-1 ring-[#e51c00]'
                    : 'border-[#8a8a8a] focus-within:border-[#005bd3] focus-within:ring-2 focus-within:ring-[#005bd3]/40 dark:border-[#616161]'
            "
        >
            <span
                v-if="prefix"
                class="pl-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >{{ prefix }}</span
            >
            <input
                :type="type || 'text'"
                :value="modelValue ?? ''"
                :placeholder="placeholder"
                :disabled="disabled"
                :min="min"
                :step="step"
                class="w-full bg-transparent px-2.5 py-1.5 text-[13px] text-[#303030] outline-none placeholder:text-[#8a8a8a] disabled:cursor-not-allowed disabled:opacity-60 dark:text-[#e3e3e3]"
                @input="
                    $emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <span
                v-if="suffix"
                class="pr-2.5 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >{{ suffix }}</span
            >
        </div>
        <p
            v-if="error"
            class="mt-1 flex items-center gap-1 text-xs text-[#e51c00]"
        >
            <svg
                viewBox="0 0 20 20"
                fill="currentColor"
                class="size-3.5 shrink-0"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM9 5a1 1 0 012 0v5a1 1 0 11-2 0V5zm1 9a1 1 0 100-2 1 1 0 000 2z"
                    clip-rule="evenodd"
                />
            </svg>
            {{ error }}
        </p>
        <p
            v-else-if="helpText"
            class="mt-1 text-xs text-[#616161] dark:text-[#b5b5b5]"
        >
            {{ helpText }}
        </p>
    </div>
</template>
