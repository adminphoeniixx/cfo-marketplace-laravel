<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

/**
 * A select you can type into, the way Shopify's category picker works.
 *
 * A plain `<select>` is fine for five options and useless for a hundred: the
 * seller knows the word they are looking for long before they can find it in a
 * list. Same v-model contract as `PSelect`, so swapping one for the other is a
 * one-line change.
 */
type Option = { value: string | number | null; label: string };

const props = defineProps<{
    label?: string;
    modelValue?: string | number | null;
    options: Option[];
    error?: string;
    helpText?: string;
    placeholder?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
}>();

const root = ref<HTMLElement | null>(null);
const field = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);
const open = ref(false);
const query = ref('');
const active = ref(0);

const same = (
    a: string | number | null | undefined,
    b: string | number | null | undefined,
) => String(a ?? '') === String(b ?? '');

const selected = computed(
    () =>
        props.options.find((option) => same(option.value, props.modelValue)) ??
        null,
);

const matches = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) {
        return props.options;
    }

    return props.options.filter((option) =>
        option.label.toLowerCase().includes(needle),
    );
});

// Typed text while open, the chosen label the rest of the time.
const display = computed(() =>
    open.value ? query.value : (selected.value?.label ?? ''),
);

const show = async () => {
    if (props.disabled) {
        return;
    }

    open.value = true;
    query.value = '';
    active.value = Math.max(
        0,
        props.options.findIndex((option) =>
            same(option.value, props.modelValue),
        ),
    );

    await nextTick();
    scrollActiveIntoView();
};

const hide = () => {
    open.value = false;
    query.value = '';
};

const choose = (option: Option) => {
    emit('update:modelValue', option.value);
    hide();
    field.value?.blur();
};

const clear = () => {
    emit('update:modelValue', null);
    hide();
};

const scrollActiveIntoView = () => {
    list.value
        ?.querySelector(`[data-index="${active.value}"]`)
        ?.scrollIntoView({ block: 'nearest' });
};

const move = async (step: number) => {
    if (!open.value) {
        await show();

        return;
    }

    const count = matches.value.length;

    if (!count) {
        return;
    }

    active.value = (active.value + step + count) % count;

    await nextTick();
    scrollActiveIntoView();
};

const onEnter = () => {
    if (!open.value) {
        return;
    }

    const option = matches.value[active.value];

    if (option) {
        choose(option);
    }
};

watch(query, () => {
    active.value = 0;
});

const onDocumentPointerDown = (event: Event) => {
    if (open.value && !root.value?.contains(event.target as Node)) {
        hide();
    }
};

onMounted(() => document.addEventListener('mousedown', onDocumentPointerDown));
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', onDocumentPointerDown),
);
</script>

<template>
    <div ref="root">
        <label
            v-if="label"
            class="mb-1 block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ label }}
        </label>
        <div class="relative">
            <input
                ref="field"
                type="text"
                role="combobox"
                autocomplete="off"
                :aria-expanded="open ? 'true' : 'false'"
                :value="display"
                :placeholder="placeholder ?? 'Search'"
                :disabled="disabled"
                class="w-full rounded-lg border bg-white py-1.5 pr-14 pl-2.5 text-[13px] text-[#303030] outline-none disabled:opacity-60 dark:bg-[#303030] dark:text-[#e3e3e3]"
                :class="
                    error
                        ? 'border-[#e51c00] ring-1 ring-[#e51c00]'
                        : 'border-[#8a8a8a] focus:border-[#005bd3] focus:ring-2 focus:ring-[#005bd3]/40 dark:border-[#616161]'
                "
                @focus="show"
                @input="
                    open = true;
                    query = ($event.target as HTMLInputElement).value;
                "
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="onEnter"
                @keydown.esc.prevent="hide"
                @keydown.tab="hide"
            />

            <button
                v-if="selected && !disabled"
                type="button"
                aria-label="Clear"
                class="absolute top-1/2 right-7 -translate-y-1/2 rounded p-0.5 text-[#616161] hover:bg-[#f1f1f1] dark:text-[#b5b5b5] dark:hover:bg-[#3a3a3a]"
                @mousedown.prevent="clear"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" class="size-3.5">
                    <path
                        d="M4.3 4.3a1 1 0 011.4 0L10 8.6l4.3-4.3a1 1 0 111.4 1.4L11.4 10l4.3 4.3a1 1 0 01-1.4 1.4L10 11.4l-4.3 4.3a1 1 0 01-1.4-1.4L8.6 10 4.3 5.7a1 1 0 010-1.4z"
                    />
                </svg>
            </button>

            <svg
                viewBox="0 0 20 20"
                fill="currentColor"
                class="pointer-events-none absolute top-1/2 right-2 size-4 -translate-y-1/2 text-[#616161] dark:text-[#b5b5b5]"
            >
                <path
                    d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"
                />
            </svg>

            <div
                v-if="open"
                ref="list"
                role="listbox"
                class="absolute top-full right-0 left-0 z-20 mt-1 max-h-60 overflow-y-auto rounded-lg border border-[#e3e3e3] bg-white py-1 shadow-lg dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
            >
                <button
                    v-for="(option, index) in matches"
                    :key="String(option.value)"
                    :data-index="index"
                    type="button"
                    role="option"
                    :aria-selected="same(option.value, modelValue)"
                    class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-[13px] text-[#303030] dark:text-[#e3e3e3]"
                    :class="
                        index === active
                            ? 'bg-[#f1f1f1] dark:bg-[#303030]'
                            : 'hover:bg-[#f7f7f7] dark:hover:bg-[#262626]'
                    "
                    @mousedown.prevent="choose(option)"
                    @mouseenter="active = index"
                >
                    <span class="min-w-0 flex-1 truncate">{{
                        option.label
                    }}</span>
                    <svg
                        v-if="same(option.value, modelValue)"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        class="size-4 shrink-0"
                    >
                        <path
                            d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z"
                        />
                    </svg>
                </button>

                <p
                    v-if="!matches.length"
                    class="px-2.5 py-2 text-[13px] text-[#616161] dark:text-[#b5b5b5]"
                >
                    No matches
                </p>
            </div>
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
