<template>
  <v-navigation-drawer :model-value="modelValue" location="right" temporary width="520" class="observation-drawer"
    @update:model-value="$emit('update:modelValue', $event)">
    <div class="pa-4">
      <div class="d-flex align-center mb-4"><div><div class="text-title-large font-weight-medium">Происхождение наблюдения</div><div v-if="observation" class="text-body-2 text-medium-emphasis">{{ formatMonth(observation.period_start) }}</div></div><v-spacer /><v-btn icon="mdi-close" variant="text" aria-label="Закрыть сведения" @click="$emit('update:modelValue', false)" /></div>
      <template v-if="observation && series && selectedImport">
        <v-list density="compact" class="pa-0">
          <v-list-subheader>Значение</v-list-subheader>
          <DetailRow label="Нормализовано" :value="observation.value === null ? 'Нет данных' : formatDecimalString(observation.value)" />
          <DetailRow label="Stored decimal" :value="observation.value ?? '—'" />
          <DetailRow label="Исходное значение" :value="observation.provenance.source_value_raw ?? '—'" />
          <DetailRow label="Причина отсутствия" :value="observation.missing_reason ?? '—'" />
          <DetailRow label="Сноска" :value="observation.provenance.footnote_marker ?? '—'" />
          <v-divider class="my-2" />
          <v-list-subheader>Series</v-list-subheader>
          <DetailRow label="Товар" :value="`${series.classifier_item.item_code} · ${series.classifier_item.item_name}`" />
          <DetailRow label="Индикатор" :value="`${series.indicator.name} · ${series.indicator.code}`" />
          <DetailRow label="Территория" :value="`${series.territory.name} · ${series.territory.code}`" />
          <DetailRow label="Измерения" :value="`${series.frequency} · ${series.comparison_basis} · ${series.unit}`" />
          <v-divider class="my-2" />
          <v-list-subheader>Импорт и источник</v-list-subheader>
          <DetailRow label="Import UUID" :value="selectedImport.public_id" copyable />
          <DetailRow label="Импортер" :value="`${selectedImport.importer.code}@${selectedImport.importer.version}`" />
          <DetailRow label="Статус" :value="selectedImport.status" />
          <DetailRow label="Файл" :value="selectedImport.source_file.original_filename" />
          <DetailRow label="SHA-256" :value="selectedImport.source_file.sha256" copyable />
          <DetailRow label="Лист" :value="observation.provenance.sheet_name" />
          <DetailRow label="Строка / столбец" :value="`${observation.provenance.source_row} / ${observation.provenance.source_column}`" />
          <DetailRow label="Ячейка" :value="observation.provenance.source_cell_address || `${observation.provenance.source_column}${observation.provenance.source_row}`" />
        </v-list>
      </template>
    </div>
  </v-navigation-drawer>
</template>
<script setup lang="ts">
import DetailRow from './ObservationDetailRow.vue'
import { formatDecimalString, formatMonth } from '../dataExplorer'
import type { StatisticalImport, StatisticalObservation, StatisticalSeriesAdmin } from '../types'
defineProps<{ modelValue: boolean; observation: StatisticalObservation | null; series: StatisticalSeriesAdmin | null; selectedImport: StatisticalImport | null }>()
defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>
<style scoped>.observation-drawer { max-width: 100vw; }</style>
