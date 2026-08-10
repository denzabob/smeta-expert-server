<template>
  <SectionCard v-if="item" variant="outlined">
    <div class="d-flex flex-wrap align-center ga-3 mb-4">
      <div><div class="text-title-medium font-weight-medium">Импорт данных</div><div class="text-body-2 text-medium-emphasis">Попытка {{ item.attempt_no }} · {{ item.source_file.original_filename }}</div></div>
      <v-spacer /><v-chip :color="statusColor(item.status)" variant="tonal">{{ importStatusLabels[item.status] }}</v-chip>
    </div>
    <v-progress-linear v-if="['pending', 'importing', 'validating'].includes(item.status)" :model-value="item.progress?.percent ?? undefined"
      :indeterminate="item.progress?.percent == null" color="primary" rounded class="mb-4" />
    <v-row dense>
      <v-col v-for="metric in counters" :key="metric.label" cols="6" sm="4" lg="2">
        <div class="text-body-1 font-weight-medium">{{ metric.value.toLocaleString('ru-RU') }}</div><div class="text-caption text-medium-emphasis">{{ metric.label }}</div>
      </v-col>
    </v-row>
    <v-alert v-if="item.failure" type="error" variant="tonal" density="compact" class="mt-4">{{ item.failure.message || 'Импорт завершился ошибкой.' }} <span v-if="item.failure.code">({{ item.failure.code }})</span></v-alert>
    <div class="text-caption text-medium-emphasis mt-4">Начат: {{ formatDate(item.timestamps.started_at) }} · Завершён: {{ formatDate(item.timestamps.finished_at) }}</div>
    <div class="d-flex justify-end ga-2 mt-4">
      <v-btn v-if="item.counters.warnings_count || item.counters.errors_count" variant="text" prepend-icon="mdi-alert-outline" @click="$emit('issues')">Проблемы</v-btn>
      <v-btn v-if="item.actions.can_retry" variant="tonal" color="primary" prepend-icon="mdi-refresh" :loading="busy" @click="$emit('retry')">Повторить импорт</v-btn>
      <v-btn v-if="item.actions.can_publish" color="primary" prepend-icon="mdi-publish" :loading="busy" @click="$emit('publish')">Опубликовать</v-btn>
    </div>
  </SectionCard>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatDate, importStatusLabels, statusColor } from '../status'
import type { StatisticalImport } from '../types'
const props = defineProps<{ item: StatisticalImport | null; busy: boolean }>()
defineEmits<{ retry: []; publish: []; issues: [] }>()
const counters = computed(() => props.item ? [
  { label: 'Строк', value: props.item.counters.rows_scanned },
  { label: 'Разобрано', value: props.item.counters.observations_parsed },
  { label: 'Принято', value: props.item.counters.observations_valid },
  { label: 'Отклонено', value: props.item.counters.observations_rejected },
  { label: 'Предупреждений', value: props.item.counters.warnings_count },
  { label: 'Ошибок', value: props.item.counters.errors_count },
] : [])
</script>
