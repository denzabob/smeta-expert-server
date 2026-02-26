<template>
  <div 
    class="row-hover-actions"
    :class="{ 'visible': visible }"
  >
    <!-- Быстрые действия как ссылки -->
    <a
      v-for="action in quickActions"
      :key="action.key"
      class="action-link"
      :class="{ 'disabled': action.disabled, [`color-${action.color}`]: action.color }"
      href="#"
      @click.prevent.stop="handleAction(action)"
      :title="action.tooltip || action.label"
    >
      <v-progress-circular
        v-if="loadingKey === action.key"
        size="14"
        width="2"
        indeterminate
        color="primary"
        class="action-icon"
      />
      <v-icon v-else size="14" class="action-icon">{{ action.icon }}</v-icon>
      <span class="action-label">{{ action.label }}</span>
    </a>
    
    <!-- Кнопка меню "..." -->
    <v-menu
      v-if="menuActions && menuActions.length > 0"
      location="bottom end"
      :close-on-content-click="true"
    >
      <template v-slot:activator="{ props }">
        <a
          class="action-link menu-link"
          href="#"
          v-bind="props"
          @click.prevent.stop
          title="Дополнительно"
        >
          <v-icon size="14">mdi-dots-horizontal</v-icon>
        </a>
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
  gap: 12px;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.15s ease, visibility 0.15s ease;
  margin-top: 2px;
  height: 20px;
  position: relative;
  z-index: 1;
}

.row-hover-actions.visible {
  opacity: 1;
  visibility: visible;
}

.action-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #65676B;
  font-size: 12px;
  font-weight: 400;
  text-decoration: none;
  cursor: pointer;
  transition: color 0.15s;
  white-space: nowrap;
}

.action-link:hover:not(.disabled) {
  color: #1877F2;
  text-decoration: underline;
}

.action-link.disabled {
  opacity: 0.4;
  cursor: not-allowed;
  pointer-events: none;
}

.action-link.color-error {
  color: #FA383E;
}

.action-link.color-error:hover:not(.disabled) {
  color: #d32f2f;
}

.action-icon {
  opacity: 0.7;
}

.action-link:hover .action-icon {
  opacity: 1;
}

.menu-link .action-label {
  display: none;
}

.loading-indicator {
  margin-left: 4px;
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
