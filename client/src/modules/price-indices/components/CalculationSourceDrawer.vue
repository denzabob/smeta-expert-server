<template>
  <v-navigation-drawer
    :model-value="modelValue"
    location="right"
    temporary
    width="520"
    class="source-drawer"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div class="pa-4">
      <div class="d-flex align-start ga-3 mb-4">
        <div class="flex-grow-1 min-width-0">
          <h2 class="text-title-large font-weight-bold">Источник статистического значения</h2>
          <div v-if="item" class="text-body-2 text-medium-emphasis mt-1">{{ formatMonth(item.period) }}</div>
        </div>
        <v-btn icon="mdi-close" variant="text" aria-label="Закрыть сведения об источнике" @click="$emit('update:modelValue', false)" />
      </div>

      <v-list v-if="item" density="compact" class="pa-0" lines="two">
        <v-list-subheader>Статистический ряд</v-list-subheader>
        <Detail label="Набор данных" :value="result.provenance.dataset.name" />
        <Detail label="Показатель" :value="result.series.indicator.name" />
        <Detail label="Товар" :value="`${result.series.classifier_item.item_code} · ${result.series.classifier_item.item_name}`" />
        <Detail label="Период" :value="formatMonth(item.period)" />
        <v-divider class="my-2" />
        <v-list-subheader>Источник</v-list-subheader>
        <Detail label="Файл" :value="result.provenance.source_file.original_filename" />
        <Detail label="SHA-256" :value="result.provenance.source_file.sha256" copyable />
        <Detail label="Лист" :value="item.source.sheet" />
        <Detail label="Ячейка" :value="item.source.cell ?? `${item.source.column}${item.source.row}`" />
        <Detail label="Исходное значение" :value="item.source.raw_value ?? '—'" />
        <Detail label="Нормализованное значение" :value="item.index" />
        <Detail v-if="item.source.footnote_marker" label="Сноска" :value="item.source.footnote_marker" />
      </v-list>
    </div>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { defineComponent, h } from 'vue'
import { formatMonth } from '../calculator'
import type { StatisticalCalculationChainItem, StatisticalCalculationResult } from '../types'
import CopyValueButton from './CopyValueButton.vue'

defineProps<{
  modelValue: boolean
  item: StatisticalCalculationChainItem | null
  result: StatisticalCalculationResult
}>()
defineEmits<{ 'update:modelValue': [value: boolean] }>()

const Detail = defineComponent({
  props: { label: { type: String, required: true }, value: { type: String, required: true }, copyable: Boolean },
  setup(props) {
    return () => h('div', { class: 'source-detail py-2' }, [
      h('div', { class: 'text-caption text-medium-emphasis' }, props.label),
      h('div', { class: 'd-flex align-center ga-1 min-width-0' }, [
        h('span', { class: 'text-body-2 text-break' }, props.value),
        props.copyable ? h(CopyValueButton, { value: props.value, tooltip: `Копировать: ${props.label}` }) : null,
      ]),
    ])
  },
})
</script>

<style scoped>
.source-drawer { max-width: 100vw; }
.min-width-0 { min-width: 0; }
.source-detail { border-bottom: 1px solid var(--md-sys-color-outline-variant); }
</style>
