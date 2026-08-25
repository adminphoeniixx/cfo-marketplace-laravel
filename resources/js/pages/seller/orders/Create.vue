<script setup lang="ts">
import { useAttrs } from 'vue';
import OrderForm from '@/pages/admin/orders/Create.vue';

/**
 * Manual order entry for a seller. The marketplace screen already locks a
 * vendor to their own store and loads only their own products, so this renders
 * the same component with the panel prefix swapped.
 */
defineOptions({ inheritAttrs: false });

const attrs = useAttrs() as unknown as InstanceType<typeof OrderForm>['$props'];
</script>

<template>
    <OrderForm v-bind="attrs" base="/seller" />
</template>
