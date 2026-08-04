<script setup lang="ts">
defineProps<{ headers: string[]; alignRight?: number[] }>();
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-max border-collapse text-left">
            <thead>
                <tr
                    class="border-b border-[#e3e3e3] bg-[#fafafa] dark:border-[#3a3a3a] dark:bg-[#232323]"
                >
                    <th
                        v-for="(header, index) in headers"
                        :key="header + index"
                        class="px-4 py-2 text-xs font-semibold whitespace-nowrap text-[#616161] dark:text-[#b5b5b5]"
                        :class="alignRight?.includes(index) ? 'text-right' : ''"
                    >
                        {{ header }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#f0f0f0] dark:divide-[#2a2a2a]">
                <slot />
            </tbody>
        </table>
    </div>
</template>
