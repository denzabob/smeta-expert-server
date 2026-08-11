<template>
  <SectionCard variant="outlined">
    <div class="d-flex flex-wrap align-start ga-3">
      <div class="flex-grow-1">
        <div class="d-flex align-center ga-1"><span class="text-title-medium font-weight-medium">{{ item.classifier_item.item_code }}</span><CopyValueButton :value="item.classifier_item.item_code" tooltip="Копировать код товара" /></div>
        <div class="text-body-2">{{ item.classifier_item.item_name }}</div>
      </div>
      <v-chip v-if="item.classifier_item.provider_code_kind === 'rosstat_local_ag'" size="small" variant="tonal">локальный код Росстата</v-chip>
    </div>
    <v-row dense class="mt-3">
      <v-col v-for="entry in details" :key="entry.label" cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ entry.label }}</div><div class="text-body-2">{{ entry.value }}</div></v-col>
    </v-row>
    <div class="d-flex align-center mt-2 text-caption text-medium-emphasis"><span title="UUID series">Series {{ shortIdentifier(item.public_id) }}</span><CopyValueButton :value="item.public_id" tooltip="Копировать UUID series" /></div>
  </SectionCard>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import CopyValueButton from './CopyValueButton.vue'
import { formatMonth, shortIdentifier } from '../dataExplorer'
import type { StatisticalSeriesAdmin } from '../types'
const props = defineProps<{ item: StatisticalSeriesAdmin }>()
const details = computed(() => [
  { label: 'Классификатор', value: props.item.classifier_item.classifier_code },
  { label: 'Индикатор', value: `${props.item.indicator.name} · ${props.item.indicator.code}` },
  { label: 'Территория', value: `${props.item.territory.name} · ${props.item.territory.code}` },
  { label: 'Частота', value: props.item.frequency },
  { label: 'База сравнения', value: props.item.comparison_basis },
  { label: 'Единица', value: props.item.unit },
  { label: 'Доступный период', value: `${formatMonth(props.item.period.from)} — ${formatMonth(props.item.period.to)}` },
  { label: 'Наблюдений', value: props.item.period.observations_count.toLocaleString('ru-RU') },
])
</script>
