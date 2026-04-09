<template>
  <div class="ssf">
    <div class="ssf-status">
      <slot name="status">
        <span class="ssf-text" :class="statusClass">{{ statusText }}</span>
      </slot>
    </div>
    <div class="ssf-actions">
      <slot name="actions">
        <v-btn
          variant="text"
          size="small"
          :disabled="saving || !isDirty"
          @click="$emit('cancel')"
        >{{ cancelLabel }}</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          size="small"
          :loading="saving"
          :disabled="saving || !isDirty"
          @click="$emit('save')"
        >{{ saveLabel }}</v-btn>
      </slot>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  isDirty: boolean
  saving: boolean
  saveLabel?: string
  cancelLabel?: string
}>(), {
  saveLabel: 'Сохранить',
  cancelLabel: 'Отменить',
})

defineEmits<{ save: []; cancel: [] }>()

const statusText = computed(() => {
  if (props.saving) return 'Сохранение...'
  if (props.isDirty) return 'Есть несохранённые изменения'
  return 'Все изменения сохранены'
})

const statusClass = computed(() => ({
  'ssf-text--dirty':  props.isDirty && !props.saving,
  'ssf-text--saved':  !props.isDirty && !props.saving,
  'ssf-text--saving': props.saving,
}))
</script>

<style scoped>
.ssf {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  gap: 12px;
  flex-wrap: wrap;
}

.ssf-status {
  flex: 1;
  min-width: 0;
}

.ssf-text {
  font-size: 13px;
}

.ssf-text--dirty  { color: rgb(var(--v-theme-warning)); }
.ssf-text--saved  { color: rgba(var(--v-theme-on-surface), 0.5); }
.ssf-text--saving { color: rgba(var(--v-theme-on-surface), 0.6); }

.ssf-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* Mobile: stack status above actions, actions go full-row */
@media (max-width: 600px) {
  .ssf {
    padding: 10px 16px;
    padding-bottom: max(10px, env(safe-area-inset-bottom));
  }

  .ssf-status {
    width: 100%;
    flex: none;
  }

  .ssf-actions {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
