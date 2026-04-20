<template>
  <v-tooltip
    :text="count > 0 ? `Есть обоснований: ${count}` : 'Нет обоснований'"
    location="bottom"
  >
    <template #activator="{ props: tooltipProps }">
      <v-chip
        v-bind="tooltipProps"
        size="small"
        :color="count > 0 ? 'primary' : 'default'"
        :variant="count > 0 ? 'tonal' : 'outlined'"
        style="cursor:pointer"
        prepend-icon="mdi-file-document-check-outline"
        @click="$emit('click')"
      >
        {{ count }}
      </v-chip>
    </template>
  </v-tooltip>
</template>

<script setup lang="ts">
defineProps<{
  count: number
}>()

defineEmits<{
  click: []
}>()
</script>
