<template>
  <div class="ds-empty-state md3-empty-state">
    <div class="md3-empty-state__icon-wrap">
      <v-icon :icon="icon" size="40" color="primary" />
    </div>
    <p class="ds-empty-state__title md3-empty-state__title">{{ title }}</p>
    <p v-if="description" class="ds-empty-state__description md3-empty-state__description">
      {{ description }}
    </p>
    <div v-if="$slots.actions" class="mt-4 md3-empty-state__actions">
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
