<template>
  <v-dialog :model-value="modelValue" max-width="1000" @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">Проблемы импорта<v-spacer /><v-btn icon="mdi-close" variant="text" @click="$emit('update:modelValue', false)" /></v-card-title>
      <v-data-table-server :headers="headers" :items="items" :items-length="total" :page="page" :items-per-page="perPage" :loading="loading" density="compact" no-data-text="Проблем не обнаружено"
        @update:page="$emit('update:page', $event)" @update:items-per-page="$emit('update:perPage', $event)">
        <template #item.severity="{ item }"><v-chip :color="item.severity === 'warning' ? 'warning' : 'error'" size="small" variant="tonal">{{ item.severity }}</v-chip></template>
        <template #item.location="{ item }">{{ item.sheet_name || '—' }}<span v-if="item.source_row">:{{ item.source_row }}{{ item.source_column || '' }}</span></template>
      </v-data-table-server>
    </v-card>
  </v-dialog>
</template>
<script setup lang="ts">
import type { StatisticalImportIssue } from '../types'
defineProps<{ modelValue: boolean; items: StatisticalImportIssue[]; total: number; page: number; perPage: number; loading: boolean }>()
defineEmits<{ 'update:modelValue': [value: boolean]; 'update:page': [page: number]; 'update:perPage': [value: number] }>()
const headers = [
  { title: 'Уровень', key: 'severity', sortable: false }, { title: 'Код', key: 'code', sortable: false },
  { title: 'Сообщение', key: 'message', sortable: false }, { title: 'Место', key: 'location', sortable: false },
  { title: 'Код позиции', key: 'classifier_item_code', sortable: false },
]
</script>
