<template>
  <SectionCard title="1. Выбранный статистический ряд" variant="outlined">
    <template #header-actions>
      <v-btn variant="text" prepend-icon="mdi-swap-horizontal" @click="$emit('change')">Изменить товар</v-btn>
    </template>

    <div class="d-flex flex-wrap align-start ga-3">
      <div class="flex-grow-1 min-width-0">
        <div class="text-title-medium font-weight-bold">{{ item.classifier_item.item_name }}</div>
        <div class="d-flex align-center ga-1 mt-1">
          <span class="text-body-2 text-medium-emphasis">{{ item.classifier_item.item_code }}</span>
          <CopyValueButton :value="item.classifier_item.item_code" tooltip="Копировать код товара" />
        </div>
      </div>
      <v-chip v-if="item.classifier_item.provider_code_kind === 'rosstat_local_ag'" size="small" variant="tonal">
        Локальный код Росстата
      </v-chip>
    </div>

    <div class="series-details mt-4">
      <div v-for="detail in details" :key="detail.label" class="series-detail">
        <div class="text-caption text-medium-emphasis">{{ detail.label }}</div>
        <div class="text-body-2 font-weight-medium">{{ detail.value }}</div>
      </div>
    </div>
  </SectionCard>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatMonth, userComparisonLabel, userFrequencyLabel, userUnitLabel } from '../calculator'
import type { UserStatisticalSeries } from '../types'
import CopyValueButton from './CopyValueButton.vue'

const props = defineProps<{ item: UserStatisticalSeries }>()
defineEmits<{ change: [] }>()

const details = computed(() => [
  { label: 'Показатель', value: props.item.indicator.name },
  { label: 'Территория', value: props.item.territory.name },
  { label: 'Периодичность', value: userFrequencyLabel(props.item.frequency) },
  { label: 'Сравнение', value: userComparisonLabel(props.item.comparison_basis) },
  { label: 'Единица', value: userUnitLabel(props.item.unit) },
  { label: 'Доступные данные', value: `${formatMonth(props.item.period.from)} — ${formatMonth(props.item.period.to)}` },
])
</script>

<style scoped>
.min-width-0 { min-width: 0; }
.series-details {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--ds-space-14);
}
@media (max-width: 760px) {
  .series-details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 520px) {
  .series-details { grid-template-columns: 1fr; }
}
</style>
