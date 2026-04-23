<template>
  <div class="app-row-actions" :class="{ 'app-row-actions--dense': dense }">
    <slot>
      <v-tooltip
        v-for="action in visibleActions"
        :key="action.key"
        :text="action.label"
        location="top"
      >
        <template #activator="{ props: tooltipProps }">
          <v-btn
            v-bind="tooltipProps"
            :icon="action.icon"
            :color="action.color"
            :variant="action.variant ?? 'text'"
            :size="dense ? 'x-small' : 'small'"
            :loading="action.loading"
            :disabled="action.disabled"
            :aria-label="action.label"
            @click.stop="emit('action', action.key)"
          />
        </template>
      </v-tooltip>
    </slot>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

export interface AppRowAction {
  key: string
  label: string
  icon: string
  color?: string
  variant?: 'flat' | 'text' | 'elevated' | 'tonal' | 'outlined' | 'plain'
  disabled?: boolean
  loading?: boolean
  hidden?: boolean
}

const props = withDefaults(
  defineProps<{
    actions?: AppRowAction[]
    dense?: boolean
  }>(),
  {
    actions: () => [],
    dense: false,
  },
)

const emit = defineEmits<{
  action: [key: string]
}>()

const visibleActions = computed(() => props.actions.filter((action) => !action.hidden))
</script>

<style scoped>
.app-row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
}

.app-row-actions--dense {
  gap: 0;
}
</style>
