<template>
  <v-navigation-drawer
    v-if="modelValue"
    :model-value="modelValue"
    location="right"
    temporary
    :scrim="scrim"
    :width="drawerWidth"
    class="app-detail-drawer"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="app-detail-drawer__shell">
      <header class="app-detail-drawer__header">
        <div class="app-detail-drawer__heading">
          <div class="app-detail-drawer__title">
            <slot name="title">{{ title }}</slot>
          </div>
          <div v-if="subtitle || $slots.subtitle" class="app-detail-drawer__subtitle">
            <slot name="subtitle">{{ subtitle }}</slot>
          </div>
        </div>
        <div class="app-detail-drawer__actions">
          <slot name="header-actions" />
          <v-btn
            icon="mdi-close"
            variant="text"
            size="small"
            aria-label="Закрыть"
            @click="emit('update:modelValue', false)"
          />
        </div>
      </header>

      <v-divider />

      <div class="app-detail-drawer__body">
        <slot />
      </div>

      <footer v-if="$slots.actions" class="app-detail-drawer__footer">
        <slot name="actions" />
      </footer>
    </div>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    title?: string
    subtitle?: string
    width?: number
    fullscreenOnMobile?: boolean
    scrim?: boolean
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    width: 440,
    fullscreenOnMobile: true,
    scrim: true,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { smAndDown } = useDisplay()

const drawerWidth = computed(() => {
  if (props.fullscreenOnMobile && smAndDown.value) return '100vw'
  return props.width
})
</script>

<style scoped>
.app-detail-drawer {
  border-left: 1px solid var(--ds-border-color) !important;
}

.app-detail-drawer__shell {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.app-detail-drawer__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-12);
  padding: var(--ds-space-16);
}

.app-detail-drawer__heading {
  min-width: 0;
}

.app-detail-drawer__title {
  color: var(--ds-text-primary);
  font-size: 0.98rem;
  font-weight: 700;
  line-height: 1.35;
}

.app-detail-drawer__subtitle {
  margin-top: var(--ds-space-4);
  color: var(--ds-text-secondary);
  font-size: 0.82rem;
  line-height: 1.45;
}

.app-detail-drawer__actions {
  display: inline-flex;
  align-items: center;
  gap: var(--ds-space-4);
  flex-shrink: 0;
}

.app-detail-drawer__body {
  flex: 1 1 0;
  overflow-y: auto;
  padding: var(--ds-space-16);
}

.app-detail-drawer__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--ds-space-8);
  padding: var(--ds-space-12) var(--ds-space-16);
  border-top: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-low), 0.72);
}
</style>
