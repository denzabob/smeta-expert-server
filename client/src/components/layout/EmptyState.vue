<template>
  <div class="ds-empty-state">
    <v-icon :icon="icon" size="48" color="medium-emphasis" />
    <p class="ds-empty-state__title">{{ title }}</p>
    <p v-if="description" class="ds-empty-state__description">{{ description }}</p>
    <div v-if="$slots.actions" class="mt-4">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    icon?: string
    title: string
    description?: string
  }>(),
  {
    icon: 'mdi-inbox-outline',
    description: undefined,
  }
)
</script>
