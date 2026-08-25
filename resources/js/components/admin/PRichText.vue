<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

/**
 * A small rich text field, in the shape Shopify's product description uses.
 *
 * Deliberately dependency-free: a full editor framework is a lot of weight for
 * one field, and everything here — bold, headings, lists, links — is a
 * `document.execCommand` away. The command API is formally deprecated but is
 * implemented everywhere and has no removal date; if that changes, this is the
 * one component to swap.
 *
 * The model is an HTML string, which is what `products.description` already
 * stores and what the seller API accepts.
 */
const model = defineModel<string>({ default: '' });

defineProps<{
    label?: string;
    error?: string;
    helpText?: string;
    placeholder?: string;
}>();

const editor = ref<HTMLDivElement | null>(null);
const showSource = ref(false);
const active = ref<Record<string, boolean>>({});

/** Push the model into the DOM, but never while the user is typing in it. */
const syncFromModel = () => {
    if (editor.value && editor.value.innerHTML !== model.value) {
        editor.value.innerHTML = model.value ?? '';
    }
};

onMounted(syncFromModel);
watch(model, () => {
    if (document.activeElement !== editor.value) {
        syncFromModel();
    }
});

const onInput = () => {
    model.value = editor.value?.innerHTML ?? '';
};

const refreshActive = () => {
    if (!editor.value) {
        return;
    }

    active.value = {
        bold: document.queryCommandState('bold'),
        italic: document.queryCommandState('italic'),
        underline: document.queryCommandState('underline'),
        insertUnorderedList: document.queryCommandState('insertUnorderedList'),
        insertOrderedList: document.queryCommandState('insertOrderedList'),
    };
};

const run = (command: string, value?: string) => {
    editor.value?.focus();
    document.execCommand(command, false, value);
    onInput();
    refreshActive();
};

const applyBlock = (event: Event) => {
    const tag = (event.target as HTMLSelectElement).value;

    run('formatBlock', `<${tag}>`);
};

const addLink = () => {
    const url = window.prompt('Link URL');

    if (!url) {
        return;
    }

    // Anything that is not obviously a URL becomes one, so a pasted
    // "example.com" does not turn into a relative link.
    const href = /^https?:\/\//i.test(url) ? url : `https://${url}`;

    run('createLink', href);
};

const tools = [
    { command: 'bold', label: 'B', title: 'Bold', class: 'font-bold' },
    { command: 'italic', label: 'I', title: 'Italic', class: 'italic' },
    {
        command: 'underline',
        label: 'U',
        title: 'Underline',
        class: 'underline',
    },
    {
        command: 'insertUnorderedList',
        label: '••',
        title: 'Bulleted list',
        class: '',
    },
    {
        command: 'insertOrderedList',
        label: '1.',
        title: 'Numbered list',
        class: '',
    },
] as const;
</script>

<template>
    <div>
        <label
            v-if="label"
            class="mb-1 block text-[13px] font-medium text-[#303030] dark:text-[#e3e3e3]"
        >
            {{ label }}
        </label>

        <div
            class="overflow-hidden rounded-lg border bg-white dark:bg-[#303030]"
            :class="
                error
                    ? 'border-[#e51c00]'
                    : 'border-[#d0d0d0] focus-within:border-[#005bd3] focus-within:ring-1 focus-within:ring-[#005bd3] dark:border-[#4a4a4a]'
            "
        >
            <!-- Toolbar -->
            <div
                class="flex flex-wrap items-center gap-0.5 border-b border-[#e3e3e3] px-1.5 py-1 dark:border-[#4a4a4a]"
            >
                <select
                    class="mr-1 rounded-md bg-transparent px-1.5 py-1 text-[13px] outline-none"
                    aria-label="Text style"
                    @change="applyBlock"
                >
                    <option value="p">Paragraph</option>
                    <option value="h2">Heading</option>
                    <option value="h3">Subheading</option>
                </select>

                <button
                    v-for="tool in tools"
                    :key="tool.command"
                    type="button"
                    class="size-7 rounded-md text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#3a3a3a]"
                    :class="[
                        tool.class,
                        active[tool.command]
                            ? 'bg-[#e3e3e3] dark:bg-[#4a4a4a]'
                            : '',
                    ]"
                    :title="tool.title"
                    @click="run(tool.command)"
                >
                    {{ tool.label }}
                </button>

                <button
                    type="button"
                    class="size-7 rounded-md text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#3a3a3a]"
                    title="Insert link"
                    @click="addLink"
                >
                    🔗
                </button>
                <button
                    type="button"
                    class="size-7 rounded-md text-[13px] hover:bg-[#f1f1f1] dark:hover:bg-[#3a3a3a]"
                    title="Clear formatting"
                    @click="run('removeFormat')"
                >
                    ⌫
                </button>

                <button
                    type="button"
                    class="ml-auto rounded-md px-2 py-1 font-mono text-xs hover:bg-[#f1f1f1] dark:hover:bg-[#3a3a3a]"
                    :class="showSource ? 'bg-[#e3e3e3] dark:bg-[#4a4a4a]' : ''"
                    title="Edit HTML"
                    @click="
                        showSource = !showSource;
                        showSource || syncFromModel();
                    "
                >
                    &lt;/&gt;
                </button>
            </div>

            <!-- Source view -->
            <textarea
                v-if="showSource"
                v-model="model"
                rows="10"
                class="block w-full resize-y bg-transparent px-3 py-2 font-mono text-[13px] outline-none"
                spellcheck="false"
            />

            <!-- Rich view -->
            <div
                v-else
                ref="editor"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                :data-placeholder="placeholder"
                class="prose-editor min-h-[11rem] px-3 py-2 text-[13px] outline-none"
                @input="onInput"
                @keyup="refreshActive"
                @mouseup="refreshActive"
                @focus="refreshActive"
            />
        </div>

        <p v-if="error" class="mt-1 text-xs font-medium text-[#e51c00]">
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

<style scoped>
.prose-editor:empty::before {
    content: attr(data-placeholder);
    color: #8a8a8a;
}

.prose-editor :deep(h2) {
    font-size: 1.0625rem;
    font-weight: 600;
    margin: 0.6em 0 0.3em;
}

.prose-editor :deep(h3) {
    font-size: 0.9375rem;
    font-weight: 600;
    margin: 0.6em 0 0.3em;
}

.prose-editor :deep(p) {
    margin: 0.35em 0;
}

.prose-editor :deep(ul),
.prose-editor :deep(ol) {
    margin: 0.35em 0;
    padding-left: 1.35em;
}

.prose-editor :deep(ul) {
    list-style: disc;
}

.prose-editor :deep(ol) {
    list-style: decimal;
}

.prose-editor :deep(a) {
    color: #005bd3;
    text-decoration: underline;
}
</style>
