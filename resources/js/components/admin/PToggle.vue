<script setup lang="ts">
/**
 * The switch Shopify uses for the one setting that governs a whole card —
 * "Inventory tracked", "Physical product". A checkbox reads as "one of these
 * options"; a switch reads as "this card is on", which is what these are.
 */
defineProps<{
    label?: string;
    modelValue?: boolean;
    helpText?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <div class="flex items-center gap-2">
        <span
            v-if="label"
            class="text-[13px] text-[#616161] dark:text-[#b5b5b5]"
        >
            {{ label }}
        </span>
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue ? 'true' : 'false'"
            :aria-label="label"
            :disabled="disabled"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-[#005bd3]/40 disabled:cursor-not-allowed disabled:opacity-60"
            :class="
                modelValue
                    ? 'bg-[#303030] dark:bg-[#e3e3e3]'
                    : 'bg-[#c9c9c9] dark:bg-[#4a4a4a]'
            "
            @click="emit('update:modelValue', !modelValue)"
        >
            <span
                class="inline-block size-4 rounded-full bg-white shadow transition-transform dark:bg-[#1a1a1a]"
                :class="modelValue ? 'translate-x-[18px]' : 'translate-x-0.5'"
            />
        </button>
        <span
            v-if="helpText"
            class="text-xs text-[#616161] dark:text-[#b5b5b5]"
            >{{ helpText }}</span
        >
    </div>
</template>
