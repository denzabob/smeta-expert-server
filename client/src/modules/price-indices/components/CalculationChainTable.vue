<template>
  <div v-if="!items.length" class="chain-empty text-body-2 text-medium-emphasis" role="status">
    Дополнительные месячные индексы не требуются.
  </div>
  <div v-else class="chain-table-wrap">
    <v-table density="compact" class="chain-table">
      <thead>
        <tr>
          <th scope="col">Период</th>
          <th scope="col">Индекс</th>
          <th scope="col">Коэффициент месяца</th>
          <th scope="col">Накопленный коэффициент</th>
          <th scope="col">Источник</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.period">
          <td class="text-no-wrap">{{ formatMonth(item.period) }}</td>
          <td class="decimal-cell">{{ formatDecimalDisplay(item.index) }} %</td>
          <td class="decimal-cell">{{ formatDecimalDisplay(item.factor, false) }}</td>
          <td class="decimal-cell">{{ formatDecimalDisplay(item.running_coefficient, false) }}</td>
          <td class="source-cell">
            <div class="text-caption text-medium-emphasis">{{ sourceLabel(item) }}</div>
            <v-btn size="small" variant="text" class="px-0" @click="$emit('show-source', item)">Подробнее</v-btn>
          </td>
        </tr>
      </tbody>
    </v-table>
  </div>
</template>

<script setup lang="ts">
import { formatDecimalDisplay, formatMonth } from '../calculator'
import type { StatisticalCalculationChainItem } from '../types'

defineProps<{ items: StatisticalCalculationChainItem[] }>()
defineEmits<{ 'show-source': [item: StatisticalCalculationChainItem] }>()

function sourceLabel(item: StatisticalCalculationChainItem): string {
  return `Лист ${item.source.sheet} · ${item.source.cell ?? `${item.source.column}${item.source.row}`}`
}
</script>

<style scoped>
.chain-table-wrap {
  max-height: 520px;
  overflow: auto;
  border: 1px solid var(--md-sys-color-outline-variant);
  border-radius: var(--md-sys-shape-corner-medium);
}
.chain-table { min-width: 820px; }
.chain-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: var(--md-sys-color-surface-container);
  color: var(--md-sys-color-on-surface-variant);
}
.decimal-cell {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.source-cell { min-width: 150px; }
.chain-empty {
  padding: var(--ds-space-18);
  border: 1px dashed var(--md-sys-color-outline-variant);
  border-radius: var(--md-sys-shape-corner-medium);
  background: var(--md-sys-color-surface-container-low);
}
</style>
