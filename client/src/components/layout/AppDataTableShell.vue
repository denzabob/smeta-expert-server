<template>
  <div class="app-data-table-shell">
    <slot name="toolbar">
      <TableToolbar v-if="$slots.search || $slots.filters || $slots.actions">
        <template v-if="$slots.search" #search>
          <slot name="search" />
        </template>
        <template v-if="$slots.filters" #filters>
          <slot name="filters" />
        </template>
        <template v-if="$slots.actions" #actions>
          <slot name="actions" />
        </template>
      </TableToolbar>
    </slot>

    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      density="compact"
      class="app-data-table-shell__alert"
    >
      {{ error }}
    </v-alert>

    <AppStateBlock
      v-else-if="empty"
      :title="emptyTitle"
      :description="emptyDescription"
      :icon="emptyIcon"
      density="compact"
    >
      <template v-if="$slots.emptyActions" #actions>
        <slot name="emptyActions" />
      </template>
    </AppStateBlock>

    <slot v-else />
  </div>
</template>

<script setup lang="ts">
import AppStateBlock from './AppStateBlock.vue'
import TableToolbar from './TableToolbar.vue'

withDefaults(
  defineProps<{
    empty?: boolean
    emptyTitle?: string
    emptyDescription?: string
    emptyIcon?: string
    error?: string | null
  }>(),
  {
    empty: false,
    emptyTitle: 'Нет данных',
    emptyDescription: undefined,
    emptyIcon: 'mdi-inbox-outline',
    error: null,
  },
)
</script>

<style scoped>
.app-data-table-shell {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-12);
  min-width: 0;
  overflow-x: auto;
}

.app-data-table-shell__alert {
  margin: 0;
}
</style>
