<template>
  <section aria-live="polite" aria-labelledby="calculation-result-title" class="result-stack">
    <SectionCard variant="tonal" class="result-card">
      <div class="d-flex align-center ga-2 mb-4">
        <v-icon icon="mdi-check-decagram-outline" color="primary" size="28" />
        <h2 id="calculation-result-title" class="text-title-large font-weight-bold">Результат расчёта</h2>
      </div>

      <v-alert v-if="result.period.factors_count === 0" type="info" variant="tonal" density="compact" class="mb-4">
        Изменение за выбранный интервал отсутствует.
      </v-alert>

      <div class="result-grid">
        <div class="result-metric result-metric--primary">
          <div class="text-body-2 text-medium-emphasis">Коэффициент изменения</div>
          <div class="result-coefficient">{{ formatDecimalDisplay(result.coefficient, false) }}</div>
        </div>
        <div v-if="result.amount" class="result-metric">
          <div class="text-body-2 text-medium-emphasis">Исходная стоимость</div>
          <div class="text-title-large font-weight-bold">{{ formatAmountDisplay(result.amount.base) }} ₽</div>
        </div>
        <div v-if="result.amount" class="result-metric">
          <div class="text-body-2 text-medium-emphasis">Стоимость после применения индексов</div>
          <div class="text-title-large font-weight-bold">{{ formatAmountDisplay(result.amount.adjusted) }} ₽</div>
        </div>
        <div class="result-metric">
          <div class="text-body-2 text-medium-emphasis">Период</div>
          <div class="text-body-1 font-weight-medium">{{ formatMonth(result.period.start) }} → {{ formatMonth(result.period.end) }}</div>
        </div>
        <div class="result-metric">
          <div class="text-body-2 text-medium-emphasis">Использовано месячных индексов</div>
          <div class="text-title-large font-weight-bold">{{ result.period.factors_count }}</div>
        </div>
      </div>

      <div class="text-caption text-medium-emphasis mt-4">
        Точное внутреннее значение: {{ result.coefficient_raw }}
      </div>
    </SectionCard>

    <SectionCard title="Как выполнен расчёт" subtitle="Все значения получены из ответа backend и не пересчитываются в браузере." variant="outlined">
      <v-expansion-panels v-model="chainPanel" variant="accordion">
        <v-expansion-panel value="chain">
          <v-expansion-panel-title>Использованные индексы ({{ result.period.factors_count }})</v-expansion-panel-title>
          <v-expansion-panel-text>
            <CalculationChainTable :items="result.chain" @show-source="$emit('show-source', $event)" />
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </SectionCard>

    <CalculationProvenancePanel :provenance="result.provenance" />
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatAmountDisplay, formatDecimalDisplay, formatMonth } from '../calculator'
import type { StatisticalCalculationChainItem, StatisticalCalculationResult } from '../types'
import CalculationChainTable from './CalculationChainTable.vue'
import CalculationProvenancePanel from './CalculationProvenancePanel.vue'

defineProps<{ result: StatisticalCalculationResult }>()
defineEmits<{ 'show-source': [item: StatisticalCalculationChainItem] }>()
const chainPanel = ref<string | undefined>()
</script>

<style scoped>
.result-stack { display: flex; flex-direction: column; gap: var(--ds-space-16); }
.result-card { border-color: var(--md-sys-color-primary); }
.result-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--ds-space-14);
}
.result-metric {
  padding: var(--ds-space-16);
  border-radius: var(--md-sys-shape-corner-medium);
  background: var(--md-sys-color-surface-container-lowest);
}
.result-metric--primary { grid-column: 1 / -1; }
.result-coefficient {
  margin-top: var(--ds-space-4);
  color: var(--md-sys-color-primary);
  font-size: clamp(1.8rem, 4vw, 2.6rem);
  line-height: 1.15;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
@media (max-width: 620px) {
  .result-grid { grid-template-columns: 1fr; }
  .result-metric--primary { grid-column: auto; }
}
</style>
