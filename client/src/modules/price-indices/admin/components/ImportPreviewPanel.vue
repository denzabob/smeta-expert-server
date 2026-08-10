<template>
  <SectionCard v-if="preview" variant="outlined">
    <div class="d-flex flex-wrap align-center ga-3 mb-4">
      <div>
        <div class="text-title-medium font-weight-medium">Предварительный анализ</div>
        <div class="text-body-2 text-medium-emphasis">{{ preview.source_file.original_filename }}</div>
      </div>
      <v-spacer />
      <v-chip :color="statusColor(preview.status)" variant="tonal">{{ previewStatusLabels[preview.status] }}</v-chip>
      <v-chip v-if="cached" color="info" size="small" variant="tonal">Использован ранее выполненный анализ</v-chip>
    </div>
    <v-progress-linear v-if="['pending', 'running'].includes(preview.status)" indeterminate color="primary" rounded class="mb-4" />
    <v-alert v-if="preview.status === 'pending'" type="info" variant="tonal" density="compact" class="mb-4">Предварительный анализ поставлен в очередь.</v-alert>
    <v-alert v-else-if="preview.status === 'running'" type="info" variant="tonal" density="compact" class="mb-4">Анализ файла выполняется.</v-alert>
    <v-alert v-else-if="preview.status === 'failed'" type="error" variant="tonal" density="compact" class="mb-4">
      {{ preview.failure?.message || 'Анализ завершился ошибкой.' }} <span v-if="preview.failure?.code">({{ preview.failure.code }})</span>
    </v-alert>
    <v-alert v-else-if="preview.status === 'expired'" type="warning" variant="tonal" density="compact" class="mb-4">Срок хранения анализа истёк.</v-alert>

    <template v-if="result">
      <v-row dense class="mb-2">
        <v-col v-for="metric in metrics" :key="metric.label" cols="6" sm="4" lg="2">
          <v-sheet border rounded class="pa-3 h-100">
            <div class="text-h6 font-weight-medium">{{ metric.value.toLocaleString('ru-RU') }}</div>
            <div class="text-caption text-medium-emphasis">{{ metric.label }}</div>
          </v-sheet>
        </v-col>
      </v-row>
      <div class="text-body-2 text-medium-emphasis mb-4">
        Годы: {{ result.workbook.detected_years.join(', ') || '—' }} · Истекает: {{ formatDate(preview.timestamps.expires_at) }}
      </div>
      <v-expansion-panels variant="accordion" class="mb-4">
        <v-expansion-panel :title="`Поддерживаемые листы (${result.workbook.supported_sheets.length})`">
          <v-expansion-panel-text>
            <v-table density="compact">
              <thead><tr><th>Лист</th><th>Год</th><th>База</th><th>Топология</th></tr></thead>
              <tbody><tr v-for="sheet in result.workbook.supported_sheets" :key="sheet.name"><td>{{ sheet.name }}</td><td>{{ sheet.year ?? '—' }}</td><td>{{ sheet.comparison_basis ?? '—' }}</td><td>{{ sheet.topology ?? '—' }}</td></tr></tbody>
            </v-table>
          </v-expansion-panel-text>
        </v-expansion-panel>
        <v-expansion-panel :title="`Игнорируемые листы (${result.workbook.ignored_sheets.length})`">
          <v-expansion-panel-text>
            <v-list density="compact"><v-list-item v-for="sheet in result.workbook.ignored_sheets" :key="sheet.name" :title="sheet.name" :subtitle="String(sheet.reason || sheet.comparison_basis || 'Неподдерживаемая структура')" /></v-list>
          </v-expansion-panel-text>
        </v-expansion-panel>
        <v-expansion-panel :title="`Примеры (${result.samples.length})`">
          <v-expansion-panel-text>
            <div class="table-scroll"><v-table density="compact"><thead><tr><th>Код</th><th>Наименование</th><th>Период</th><th>Значение</th><th>Ячейка</th></tr></thead>
              <tbody><tr v-for="(sample, index) in result.samples" :key="`${sample.item_code}-${index}`"><td class="text-no-wrap">{{ sample.item_code }}</td><td>{{ sample.item_name || '—' }}</td><td>{{ sample.period_start || '—' }}</td><td>{{ sample.value ?? '—' }}</td><td>{{ sample.sheet_name || '—' }}<span v-if="sample.source_cell_address"> / {{ sample.source_cell_address }}</span></td></tr></tbody>
            </v-table></div>
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </template>
    <div class="d-flex flex-wrap justify-end ga-2">
      <v-btn v-if="preview.actions.can_retry" variant="tonal" color="primary" prepend-icon="mdi-refresh" :loading="busy" @click="$emit('retry')">Повторить анализ</v-btn>
      <v-btn v-if="canImport" color="primary" prepend-icon="mdi-database-import-outline" :loading="busy" @click="$emit('startImport')">Импортировать данные</v-btn>
    </div>
  </SectionCard>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatDate, previewStatusLabels, statusColor } from '../status'
import type { StatisticalImportPreview, StatisticalImportPreviewResult } from '../types'
const props = defineProps<{ preview: StatisticalImportPreview | null; result: StatisticalImportPreviewResult | null; cached: boolean; busy: boolean }>()
defineEmits<{ retry: []; startImport: [] }>()
const canImport = computed(() => props.preview?.status === 'ready' && !!props.result && props.result.counts.fatal_errors === 0)
const metrics = computed(() => props.result ? [
  { label: 'Листы', value: props.result.workbook.sheets_total },
  { label: 'Поддержано', value: props.result.workbook.supported_sheets.length },
  { label: 'Пропущено', value: props.result.workbook.ignored_sheets.length },
  { label: 'Позиций', value: props.result.counts.commodity_occurrences },
  { label: 'Уникальных кодов', value: props.result.counts.unique_classifier_items },
  { label: 'Наблюдений', value: props.result.counts.observation_candidates },
  { label: 'Числовых', value: props.result.counts.numeric },
  { label: 'Пропусков', value: props.result.counts.missing },
  { label: 'Со сноской', value: props.result.counts.footnoted },
  { label: 'Предупреждений', value: props.result.counts.warnings },
  { label: 'Критических', value: props.result.counts.fatal_errors },
] : [])
</script>
<style scoped>.table-scroll { overflow-x: auto; }</style>
