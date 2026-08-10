<template>
  <SectionCard variant="outlined" content-class="pa-0">
    <div class="d-flex align-center px-4 py-3">
      <div>
        <div class="text-title-medium font-weight-medium">Исходные файлы</div>
        <div class="text-body-2 text-medium-emphasis">Официальные версии и их состояние.</div>
      </div>
      <v-spacer />
      <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="$emit('refresh')">Обновить</v-btn>
    </div>
    <v-divider />
    <v-data-table-server :headers="headers" :items="items" :items-length="total" :loading="loading"
      :page="page" :items-per-page="perPage" density="compact" no-data-text="Файлы ещё не загружены"
      @update:page="$emit('update:page', $event)" @update:items-per-page="$emit('update:perPage', $event)">
      <template #item.original_filename="{ item }">
        <div class="py-2 file-name">
          <div class="font-weight-medium text-truncate">{{ item.original_filename }}</div>
          <div class="text-caption text-medium-emphasis">{{ shortHash(item.sha256) }}</div>
        </div>
      </template>
      <template #item.period="{ item }">{{ formatPeriod(item.reporting_year, item.reporting_month) }}</template>
      <template #item.source="{ item }">{{ item.source?.name || 'Без источника' }}</template>
      <template #item.file_size="{ item }">{{ formatBytes(item.file_size) }}</template>
      <template #item.created_at="{ item }">{{ formatDate(item.created_at) }}</template>
      <template #item.status="{ item }">
        <v-chip :color="statusColor(item.status)" size="small" variant="tonal">
          {{ sourceStatusLabels[item.status] }}
        </v-chip>
        <v-icon v-if="item.active" icon="mdi-check-decagram" color="success" size="small" class="ml-1" title="Текущий активный файл" />
      </template>
      <template #item.validation_status="{ item }">
        <v-chip :color="statusColor(item.validation_status)" size="x-small" variant="outlined">{{ item.validation_status }}</v-chip>
      </template>
      <template #item.actions="{ item }">
        <div class="d-flex align-center justify-end ga-1 action-cell">
          <v-btn v-if="item.status === 'pending_review'" size="x-small" variant="tonal" color="success"
            prepend-icon="mdi-check" :disabled="busyId === item.public_id" @click="$emit('approve', item)">Одобрить</v-btn>
          <v-btn v-if="item.status === 'pending_review'" size="x-small" variant="text" color="error"
            icon="mdi-close" :disabled="busyId === item.public_id" title="Отклонить" @click="$emit('reject', item)" />
          <v-btn v-if="item.status === 'approved'" size="x-small" variant="tonal" color="primary"
            prepend-icon="mdi-check-decagram-outline" :disabled="busyId === item.public_id" @click="$emit('activate', item)">Активировать</v-btn>
          <v-btn v-if="item.status === 'active'" size="x-small" variant="tonal" color="primary"
            prepend-icon="mdi-magnify-scan" :disabled="busyId === item.public_id" @click="$emit('preview', item)">Анализ</v-btn>
          <v-btn size="x-small" variant="text" icon="mdi-download" :disabled="busyId === item.public_id"
            title="Скачать" @click="$emit('download', item)" />
        </div>
      </template>
    </v-data-table-server>
  </SectionCard>
</template>

<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatBytes, formatDate, formatPeriod, sourceStatusLabels, statusColor } from '../status'
import type { StatisticalSourceFile } from '../types'

defineProps<{ items: StatisticalSourceFile[]; total: number; page: number; perPage: number; loading: boolean; busyId: string | null }>()
defineEmits<{
  refresh: []
  'update:page': [page: number]
  'update:perPage': [perPage: number]
  approve: [file: StatisticalSourceFile]
  reject: [file: StatisticalSourceFile]
  activate: [file: StatisticalSourceFile]
  preview: [file: StatisticalSourceFile]
  download: [file: StatisticalSourceFile]
}>()

const headers = [
  { title: 'Файл / SHA', key: 'original_filename', sortable: false },
  { title: 'Период', key: 'period', sortable: false },
  { title: 'Источник', key: 'source', sortable: false },
  { title: 'Размер', key: 'file_size', sortable: false },
  { title: 'Загружен', key: 'created_at', sortable: false },
  { title: 'Статус', key: 'status', sortable: false },
  { title: 'Проверка', key: 'validation_status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
function shortHash(value: string) { return `${value.slice(0, 10)}…${value.slice(-6)}` }
</script>

<style scoped>
.file-name { max-width: 260px; }
.action-cell { min-width: 180px; }
</style>
