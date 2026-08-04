<script setup lang="ts">
defineProps<{
    label?: string;
    modelValue?: boolean;
    helpText?: string;
    disabled?: boolean;
}>();

defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <label
        class="flex cursor-pointer items-start gap-2"
        :class="disabled ? 'cursor-not-allowed opacity-60' : ''"
    >
        <input
            type="checkbox"
            :checked="modelValue"
            :disabled="disabled"
            class="mt-0.5 size-4 rounded border-[#8a8a8a] text-[#303030] accent-[#303030] focus:ring-2 focus:ring-[#005bd3]/40 dark:accent-[#e3e3e3]"
            @change="
                $emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).checked,
                )
            "
        />
        <span>
            <span class="text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                ><slot>{{ label }}</slot></span
            >
            <span
                v-if="helpText"
                class="block text-xs text-[#616161] dark:text-[#b5b5b5]"
                >{{ helpText }}</span
            >
        </span>
    </label>
</template>
