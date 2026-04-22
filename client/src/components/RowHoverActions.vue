<template>
  <div 
    class="row-hover-actions"
    :class="{ 'visible': visible, 'row-hover-actions--dense': dense }"
  >
    <v-tooltip
      v-for="action in quickActions"
      :key="action.key"
      location="top"
      :text="action.tooltip || action.label"
    >
      <template #activator="{ props: tooltipProps }">
        <v-btn
          v-bind="tooltipProps"
          class="action-icon-btn"
          :class="[{ 'action-icon-btn--destructive': action.color === 'error' }, action.color ? `color-${action.color}` : '']"
          :disabled="action.disabled"
          :aria-label="action.tooltip || action.label"
          icon
          size="x-small"
          variant="text"
          @click.stop="handleAction(action)"
        >
          <v-progress-circular
            v-if="loadingKey === action.key"
            size="14"
            width="2"
            indeterminate
            color="primary"
          />
          <v-icon v-else size="16">{{ action.icon }}</v-icon>
        </v-btn>
      </template>
    </v-tooltip>
    
    <!-- Кнопка меню "..." -->
    <v-menu
      v-if="menuActions && menuActions.length > 0"
      location="bottom end"
      :close-on-content-click="true"
    >
      <template v-slot:activator="{ props }">
        <v-btn
          class="action-icon-btn action-icon-btn--menu"
          v-bind="props"
          aria-label="Дополнительно"
          icon
          size="x-small"
          variant="text"
          @click.stop
        >
          <v-icon size="16">mdi-dots-horizontal</v-icon>
        </v-btn>
      </template>
      <v-list density="compact" class="actions-menu">
        <v-list-item
          v-for="action in menuActions"
          :key="action.key"
          :disabled="action.disabled"
          @click="handleAction(action)"
        >
          <template v-slot:prepend>
            <v-icon size="16" :color="action.color || 'default'">{{ action.icon }}</v-icon>
          </template>
          <v-list-item-title>{{ action.label }}</v-list-item-title>
        </v-list-item>
      </v-list>
    </v-menu>
    
    <!-- Индикатор загрузки -->
    <v-progress-circular
      v-if="loading"
      size="14"
      width="2"
      indeterminate
      color="primary"
      class="loading-indicator"
    />
  </div>
</template>

<script setup lang="ts">
export interface RowAction {
  key: string
  icon: string
  label: string
  tooltip?: string
  disabled?: boolean
  color?: string
  handler?: () => void
}

const props = defineProps<{
  rowId: number | string
  quickActions: RowAction[]
  menuActions?: RowAction[]
  visible: boolean
  loading?: boolean
  loadingKey?: string | null
  dense?: boolean
}>()

const emit = defineEmits<{
  (e: 'action', payload: { rowId: number | string, actionKey: string }): void
}>()

const handleAction = (action: RowAction) => {
  if (action.disabled) return
  if (action.handler) {
    action.handler()
  } else {
    emit('action', { rowId: props.rowId, actionKey: action.key })
  }
}
</script>

<style scoped>
.row-hover-actions {
  display: flex;
  align-items: center;
  gap: 4px;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.18s ease, visibility 0.18s ease, transform 0.18s ease;
  transform: translateY(2px);
  margin-top: 2px;
  min-height: 24px;
  position: relative;
  z-index: 1;
  width: fit-content;
  padding: 0;
  border-radius: 0;
  background: transparent;
  border: none;
}

.row-hover-actions--dense {
  gap: 2px;
  margin-top: 0;
  min-height: 20px;
}

.row-hover-actions.visible {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.loading-indicator {
  margin-left: 4px;
}

.action-icon-btn {
  color: rgba(var(--v-theme-on-surface-variant), 1);
  transition:
    background-color 0.18s ease,
    color 0.18s ease,
    box-shadow 0.18s ease,
    transform 0.18s ease;
}

.row-hover-actions--dense :deep(.v-btn) {
  width: 22px !important;
  height: 22px !important;
}

.row-hover-actions--dense .action-icon-btn {
  min-width: 22px;
}

.row-hover-actions--dense .action-icon-btn :deep(.v-btn__content) {
  gap: 0;
}

.row-hover-actions--dense .action-icon-btn :deep(.v-icon) {
  font-size: 15px !important;
}

.action-icon-btn:hover {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgba(var(--v-theme-primary), 1);
}

.action-icon-btn:focus-visible {
  background: rgba(var(--v-theme-primary), 0.16);
  color: rgba(var(--v-theme-primary), 1);
  box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.16);
}

.action-icon-btn:active {
  transform: scale(0.96);
}

.action-icon-btn--destructive {
  color: rgba(var(--v-theme-error), 0.92);
}

.action-icon-btn--destructive:hover,
.action-icon-btn--destructive:focus-visible {
  background: rgba(var(--v-theme-error), 0.12);
  color: rgba(var(--v-theme-error), 1);
}

.action-icon-btn--menu:hover,
.action-icon-btn--menu:focus-visible {
  background: rgba(var(--v-theme-secondary), 0.12);
  color: rgba(var(--v-theme-secondary), 1);
}

.actions-menu {
  min-width: 160px;
}

/* Touch-устройства: меню видно всегда для выбранных */
@media (hover: none) {
  .row-hover-actions {
    opacity: 1;
    visibility: visible;
  }
}
</style>
