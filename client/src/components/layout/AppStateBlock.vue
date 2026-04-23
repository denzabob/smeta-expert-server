<template>
  <div class="app-state-block" :class="[`app-state-block--${tone}`, `app-state-block--${density}`]">
    <v-progress-circular
      v-if="loading"
      indeterminate
      color="primary"
      :size="density === 'compact' ? 24 : 36"
      :width="3"
    />
    <v-icon
      v-else-if="icon"
      :icon="icon"
      :size="density === 'compact' ? 28 : 40"
      :color="iconColor"
    />

    <div class="app-state-block__body">
      <div v-if="title" class="app-state-block__title">{{ title }}</div>
      <div v-if="description" class="app-state-block__description">{{ description }}</div>
      <slot />
    </div>

    <div v-if="$slots.actions" class="app-state-block__actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    title?: string
    description?: string
    icon?: string
    tone?: 'neutral' | 'info' | 'success' | 'warning' | 'error'
    density?: 'compact' | 'default'
    loading?: boolean
  }>(),
  {
    title: undefined,
    description: undefined,
    icon: 'mdi-information-outline',
    tone: 'neutral',
    density: 'default',
    loading: false,
  },
)

const iconColor = computed(() => {
  if (props.tone === 'neutral') return 'medium-emphasis'
  return props.tone
})
</script>

<style scoped>
.app-state-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--ds-space-10);
  padding: var(--ds-space-32) var(--ds-space-20);
  text-align: center;
  color: var(--ds-text-secondary);
}

.app-state-block--compact {
  padding: var(--ds-space-20) var(--ds-space-16);
}

.app-state-block__body {
  display: grid;
  gap: var(--ds-space-4);
  max-width: 460px;
}

.app-state-block__title {
  color: var(--ds-text-primary);
  font-size: 0.95rem;
  font-weight: 700;
  line-height: 1.35;
}

.app-state-block__description {
  color: var(--ds-text-secondary);
  font-size: 0.875rem;
  line-height: 1.5;
}

.app-state-block__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--ds-space-8);
  margin-top: var(--ds-space-6);
}
</style>
