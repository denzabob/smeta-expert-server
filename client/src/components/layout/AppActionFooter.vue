<template>
  <div class="app-action-footer" :class="{ 'app-action-footer--sticky': sticky }">
    <div v-if="$slots.status || statusText" class="app-action-footer__status">
      <slot name="status">{{ statusText }}</slot>
    </div>
    <div class="app-action-footer__spacer" />
    <div class="app-action-footer__actions">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    statusText?: string
    sticky?: boolean
  }>(),
  {
    statusText: undefined,
    sticky: false,
  },
)
</script>

<style scoped>
.app-action-footer {
  display: flex;
  align-items: center;
  gap: var(--ds-space-12);
  padding: var(--ds-space-10) var(--ds-space-16);
  border-top: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-low), 0.72);
}

.app-action-footer--sticky {
  position: sticky;
  bottom: 0;
  z-index: 2;
  backdrop-filter: blur(12px);
}

.app-action-footer__status {
  min-width: 0;
  color: var(--ds-text-secondary);
  font-size: 0.82rem;
  line-height: 1.4;
}

.app-action-footer__spacer {
  flex: 1 1 auto;
}

.app-action-footer__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--ds-space-8);
}

@media (max-width: 600px) {
  .app-action-footer {
    align-items: stretch;
    flex-direction: column;
  }

  .app-action-footer__actions {
    justify-content: stretch;
  }

  .app-action-footer__actions :deep(.v-btn) {
    flex: 1 1 auto;
  }
}
</style>
