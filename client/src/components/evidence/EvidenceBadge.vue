<template>
  <v-chip
    size="small"
    :color="count > 0 ? 'primary' : 'default'"
    :variant="count > 0 ? 'tonal' : 'outlined'"
    style="cursor:pointer"
    :title="count > 0 ? 'Просмотреть обоснования' : 'Добавить обоснование'"
    prepend-icon="mdi-file-document-check-outline"
    @click="$emit('click')"
  >
    {{ count }}
  </v-chip>
</template>

<script setup lang="ts">
defineProps<{
  count: number
}>()

defineEmits<{
  click: []
}>()
</script>
