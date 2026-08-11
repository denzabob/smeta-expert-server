<template>
  <SectionCard variant="outlined">
    <div class="d-flex align-center ga-3">
      <v-avatar color="primary" variant="tonal" size="40"><v-icon icon="mdi-database-check-outline" /></v-avatar>
      <div class="flex-grow-1">
        <div class="text-title-medium font-weight-medium">Текущая публикация</div>
        <template v-if="item">
          <div class="text-body-2">{{ item.source_file.original_filename }}</div>
          <div class="text-caption text-medium-emphasis">Опубликовано {{ formatDate(item.timestamps.published_at) }}</div>
        </template>
        <div v-else class="text-body-2 text-medium-emphasis">Для набора ещё нет опубликованного импорта.</div>
      </div>
      <div v-if="item" class="d-flex flex-wrap align-center ga-2">
        <v-chip color="success" size="small" variant="tonal">{{ item.counters.observations_valid.toLocaleString('ru-RU') }} наблюдений</v-chip>
        <v-btn :to="{ path: '/admin/indices/data', query: { import: item.public_id } }" size="small" variant="tonal" color="primary" prepend-icon="mdi-chart-timeline-variant">Просмотреть данные</v-btn>
      </div>
    </div>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatDate } from '../status'
import type { StatisticalImport } from '../types'
defineProps<{ item: StatisticalImport | null }>()
</script>
