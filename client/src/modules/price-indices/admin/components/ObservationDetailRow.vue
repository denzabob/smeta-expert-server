<template>
  <v-list-item class="px-0">
    <template #title><span class="text-caption text-medium-emphasis">{{ label }}</span></template>
    <template #subtitle><span class="text-body-2 text-wrap text-high-emphasis">{{ value }}</span></template>
    <template v-if="copyable" #append><CopyValueButton :value="value" :tooltip="`Копировать: ${label}`" /></template>
  </v-list-item>
</template>
<script setup lang="ts">
import CopyValueButton from './CopyValueButton.vue'
defineProps<{ label: string; value: string; copyable?: boolean }>()
</script>
