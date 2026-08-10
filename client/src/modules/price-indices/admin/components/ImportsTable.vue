<template>
  <SectionCard variant="outlined" content-class="pa-0">
    <v-data-table-server :headers="headers" :items="items" :items-length="total" :page="page"
      :items-per-page="perPage" :loading="loading" density="compact" no-data-text="Импорты ещё не запускались"
      @update:page="$emit('update:page', $event)" @update:items-per-page="$emit('update:perPage', $event)">
      <template #item.created_at="{ item }">{{ formatDate(item.timestamps.created_at) }}</template>
      <template #item.dataset="{ item }"><div class="text-body-2">{{ item.dataset.name }}</div><div class="text-caption text-medium-emphasis">{{ item.dataset.code }}</div></template>
      <template #item.source_file="{ item }"><div class="file-cell text-truncate">{{ item.source_file.original_filename }}</div></template>
      <template #item.importer="{ item }"><span class="text-no-wrap">{{ item.importer.code }}@{{ item.importer.version }}</span></template>
      <template #item.status="{ item }"><v-chip :color="statusColor(item.status)" size="small" variant="tonal">{{ importStatusLabels[item.status] }}</v-chip></template>
      <template #item.observations="{ item }">{{ item.counters.observations_valid.toLocaleString('ru-RU') }}</template>
      <template #item.published_at="{ item }">{{ formatDate(item.timestamps.published_at) }}</template>
      <template #item.actions="{ item }"><v-btn size="x-small" variant="text" icon="mdi-eye-outline" title="Открыть" @click="$emit('open', item)" /></template>
    </v-data-table-server>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatDate, importStatusLabels, statusColor } from '../status'
import type { StatisticalImport } from '../types'
defineProps<{ items: StatisticalImport[]; total: number; page: number; perPage: number; loading: boolean }>()
defineEmits<{ open: [item: StatisticalImport]; 'update:page': [value: number]; 'update:perPage': [value: number] }>()
const headers = [
  { title: 'Создан', key: 'created_at', sortable: false }, { title: 'Набор', key: 'dataset', sortable: false },
  { title: 'Файл', key: 'source_file', sortable: false }, { title: 'Импортер', key: 'importer', sortable: false },
  { title: 'Попытка', key: 'attempt_no', sortable: false }, { title: 'Статус', key: 'status', sortable: false },
  { title: 'Наблюдений', key: 'observations', sortable: false }, { title: 'Предупр.', key: 'counters.warnings_count', sortable: false },
  { title: 'Ошибки', key: 'counters.errors_count', sortable: false }, { title: 'Опубликован', key: 'published_at', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
</script>
<style scoped>.file-cell { max-width: 220px; }</style>
