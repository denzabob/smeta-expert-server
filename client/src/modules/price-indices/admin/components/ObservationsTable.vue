<template>
  <SectionCard variant="outlined" content-class="pa-0">
    <v-data-table-server :headers="headers" :items="items" :items-length="total" :page="page"
      :items-per-page="perPage" :loading="loading" density="compact" no-data-text="В выбранном периоде наблюдений нет"
      @update:page="$emit('update:page', $event)" @update:items-per-page="$emit('update:perPage', $event)">
      <template #item.period_start="{ item }">{{ formatMonth(item.period_start) }}</template>
      <template #item.value="{ item }">
        <span v-if="item.value !== null" class="font-weight-medium">{{ formatDecimalString(item.value) }}</span>
        <span v-else class="text-medium-emphasis">Нет данных</span>
        <v-chip v-if="item.provenance.footnote_marker" size="x-small" variant="tonal" class="ml-2">сноска</v-chip>
      </template>
      <template #item.status="{ item }">
        <v-chip v-if="item.value === null" color="warning" size="x-small" variant="tonal">{{ missingReasonLabel(item.missing_reason) }}</v-chip>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.source="{ item }"><span class="text-no-wrap">{{ sourceCellLabel(item) }}</span></template>
      <template #item.actions="{ item }"><v-btn size="x-small" variant="text" prepend-icon="mdi-information-outline" @click="$emit('detail', item)">Подробнее</v-btn></template>
    </v-data-table-server>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatDecimalString, formatMonth, sourceCellLabel } from '../dataExplorer'
import type { StatisticalObservation } from '../types'
defineProps<{ items: StatisticalObservation[]; total: number; page: number; perPage: number; loading: boolean }>()
defineEmits<{ detail: [item: StatisticalObservation]; 'update:page': [value: number]; 'update:perPage': [value: number] }>()
const headers = [
  { title: 'Период', key: 'period_start', sortable: false }, { title: 'Значение', key: 'value', sortable: false },
  { title: 'Статус', key: 'status', sortable: false }, { title: 'Источник', key: 'source', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
const labels: Record<string, string> = { blank: 'Пусто', ellipsis: 'Многоточие', three_dots: 'Три точки', dash: 'Прочерк' }
function missingReasonLabel(reason: string | null) { return reason ? (labels[reason] ?? reason) : 'Нет данных' }
</script>
