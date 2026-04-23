<template>
  <section class="app-form-section" :class="{ 'app-form-section--compact': compact }">
    <header v-if="title || description || $slots.header || $slots.actions" class="app-form-section__header">
      <div class="app-form-section__heading">
        <slot name="header">
          <div v-if="title" class="app-form-section__title">{{ title }}</div>
          <div v-if="description" class="app-form-section__description">{{ description }}</div>
        </slot>
      </div>
      <div v-if="$slots.actions" class="app-form-section__actions">
        <slot name="actions" />
      </div>
    </header>

    <div class="app-form-section__body">
      <slot />
    </div>
  </section>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    description?: string
    compact?: boolean
  }>(),
  {
    title: undefined,
    description: undefined,
    compact: false,
  },
)
</script>

<style scoped>
.app-form-section {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-14);
  padding: var(--ds-space-16);
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgba(var(--v-theme-surface-container-lowest), 0.82);
}

.app-form-section--compact {
  gap: var(--ds-space-10);
  padding: var(--ds-space-12);
}

.app-form-section__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-12);
}

.app-form-section__heading {
  min-width: 0;
}

.app-form-section__title {
  color: var(--ds-text-primary);
  font-size: 0.95rem;
  font-weight: 700;
  line-height: 1.35;
}

.app-form-section__description {
  margin-top: var(--ds-space-4);
  color: var(--ds-text-secondary);
  font-size: 0.83rem;
  line-height: 1.5;
}

.app-form-section__actions {
  flex-shrink: 0;
}

.app-form-section__body {
  display: grid;
  gap: var(--ds-space-12);
}
</style>
