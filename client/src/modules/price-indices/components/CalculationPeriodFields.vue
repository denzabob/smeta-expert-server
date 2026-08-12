<template>
  <SectionCard
    title="2. Период"
    :subtitle="`Доступны данные: ${formatMonth(availableFrom)} — ${formatMonth(availableTo)}`"
    variant="outlined"
  >
    <div class="period-grid">
      <v-text-field
        :model-value="start"
        type="month"
        label="Начальный период"
        :min="availableFrom"
        :max="availableTo"
        :error-messages="startError"
        @update:model-value="$emit('update:start', String($event ?? ''))"
      />
      <v-text-field
        :model-value="end"
        type="month"
        label="Конечный период"
        :min="availableFrom"
        :max="availableTo"
        :error-messages="endError"
        @update:model-value="$emit('update:end', String($event ?? ''))"
      />
    </div>

    <v-alert type="info" variant="tonal" density="compact" icon="mdi-information-outline">
      Для индексов «к предыдущему месяцу» начальный месяц является базовым. В расчёт входят изменения со следующего месяца по выбранный конечный месяц включительно.
    </v-alert>

    <v-expansion-panels variant="accordion" class="mt-3">
      <v-expansion-panel title="Как рассчитывается коэффициент?">
        <v-expansion-panel-text>
          Для каждого месяца после начального периода используется индекс изменения к предыдущему месяцу. Месячные коэффициенты последовательно перемножаются на backend. Поэтому индекс самого начального месяца в расчёт не включается.
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
  </SectionCard>
</template>

<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import { formatMonth } from '../calculator'

defineProps<{
  start: string
  end: string
  availableFrom: string
  availableTo: string
  startError: string
  endError: string
}>()
defineEmits<{ 'update:start': [value: string]; 'update:end': [value: string] }>()
</script>

<style scoped>
.period-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--ds-space-16);
}
@media (max-width: 620px) {
  .period-grid { grid-template-columns: 1fr; }
}
</style>
