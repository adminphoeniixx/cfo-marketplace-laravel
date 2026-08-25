<script setup lang="ts">
import { useAttrs } from 'vue';
import NotificationsScreen from '@/pages/admin/notifications/Index.vue';

/**
 * The seller's notification centre. Same screen, same controller — the pile
 * has always been the signed-in user's own, so only the prefix changes.
 */
defineOptions({ inheritAttrs: false });

const attrs = useAttrs() as unknown as InstanceType<
    typeof NotificationsScreen
>['$props'];
</script>

<template>
    <NotificationsScreen v-bind="attrs" base="/seller" />
</template>
