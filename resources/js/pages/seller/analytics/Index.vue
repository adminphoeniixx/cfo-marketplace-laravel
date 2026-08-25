<script setup lang="ts">
import { useAttrs } from 'vue';
import AnalyticsScreen from '@/pages/admin/analytics/Index.vue';

/**
 * The seller's analytics, which is the marketplace screen with the store
 * filter locked to them — every figure on it is already built from
 * `AnalyticsController::resolveVendor()`. Rendering the same component keeps
 * one implementation of the charts; only the panel prefix differs.
 */
defineOptions({ inheritAttrs: false });

// The controller hands this page exactly the props the marketplace screen
// declares. TypeScript cannot see that through `$attrs`, hence the cast.
const attrs = useAttrs() as unknown as InstanceType<
    typeof AnalyticsScreen
>['$props'];
</script>

<template>
    <AnalyticsScreen v-bind="attrs" base="/seller" />
</template>
