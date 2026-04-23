<template>
  <div class="app-detail-meta-grid">
    <slot>
      <div
        v-for="item in items"
        :key="item.key ?? item.label"
        class="app-detail-meta-grid__item"
      >
        <div class="app-detail-meta-grid__label">{{ item.label }}</div>
        <div class="app-detail-meta-grid__value">
          {{ item.value ?? '—' }}
        </div>
      </div>
    </slot>
  </div>
</template>

<script setup lang="ts">
export interface AppDetailMetaItem {
  key?: string
  label: string
  value?: string | number | null
}

withDefaults(
  defineProps<{
    items?: AppDetailMetaItem[]
  }>(),
  {
    items: () => [],
  },
)
</script>

<style scoped>
.app-detail-meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(128px, 1fr));
  gap: var(--ds-space-8);
}

.app-detail-meta-grid__item {
  min-width: 0;
  padding: var(--ds-space-10) var(--ds-space-12);
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-10);
  background: rgba(var(--v-theme-surface-container-high), 0.64);
}

.app-detail-meta-grid__label {
  color: var(--ds-text-tertiary);
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 1.3;
  text-transform: uppercase;
}

.app-detail-meta-grid__value {
  margin-top: var(--ds-space-4);
  color: var(--ds-text-primary);
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
</style>
