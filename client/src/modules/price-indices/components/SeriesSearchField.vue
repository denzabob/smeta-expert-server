<template>
  <SectionCard
    title="1. Показатель и товар"
    subtitle="Найдите товар по классификационному коду или наименованию и выберите конкретный статистический ряд."
    variant="outlined"
  >
    <v-text-field
      :model-value="query"
      label="Код или наименование товара"
      placeholder="31.02 или кухонная мебель"
      prepend-inner-icon="mdi-magnify"
      clearable
      autocomplete="off"
      :loading="loading"
      :aria-busy="loading"
      @update:model-value="$emit('update:query', String($event ?? ''))"
    />

    <div v-if="hint" class="text-body-2 text-medium-emphasis mt-2" role="status">{{ hint }}</div>
    <v-alert v-else-if="error" type="error" variant="tonal" density="compact" class="mt-3">
      {{ error }}
    </v-alert>
    <v-alert v-else-if="searched && !loading && !items.length" type="info" variant="tonal" density="compact" class="mt-3">
      По вашему запросу статистические ряды не найдены.
    </v-alert>

    <v-list v-if="items.length" class="series-results mt-2 pa-0" aria-label="Результаты поиска статистических рядов">
      <v-list-item
        v-for="item in items"
        :key="item.public_id"
        lines="three"
        tabindex="0"
        @click="$emit('select', item)"
        @keydown.enter.prevent="$emit('select', item)"
        @keydown.space.prevent="$emit('select', item)"
      >
        <template #title>
          <span class="font-weight-bold">{{ item.classifier_item.item_code }}</span>
          <v-chip
            v-if="item.classifier_item.provider_code_kind === 'rosstat_local_ag'"
            size="x-small"
            variant="tonal"
            class="ml-2"
          >
            Локальный код Росстата
          </v-chip>
        </template>
        <template #subtitle>
          <div class="text-body-2 mt-1">{{ item.classifier_item.item_name }}</div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ item.indicator.name }} · {{ item.territory.name }} ·
            {{ userFrequencyLabel(item.frequency) }} · {{ userComparisonLabel(item.comparison_basis) }}
          </div>
        </template>
        <template #append><v-icon icon="mdi-chevron-right" aria-hidden="true" /></template>
      </v-list-item>
    </v-list>

    <div v-if="total > items.length" class="d-flex justify-center mt-3">
      <v-btn variant="tonal" color="primary" :loading="loading" @click="$emit('load-more')">
        Показать ещё
      </v-btn>
    </div>
  </SectionCard>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { userComparisonLabel, userFrequencyLabel } from '../calculator'
import type { UserStatisticalSeries } from '../types'

const props = defineProps<{
  query: string
  items: UserStatisticalSeries[]
  total: number
  loading: boolean
  searched: boolean
  error: string
}>()
defineEmits<{
  'update:query': [value: string]
  select: [item: UserStatisticalSeries]
  'load-more': []
}>()

const hint = computed(() => {
  const value = props.query.trim()
  if (!value) return 'Для поиска по названию введите минимум 2 символа. Код можно искать с первой цифры.'
  if (!/^\d/u.test(value) && value.length < 2) return 'Введите ещё один символ наименования.'
  return ''
})
</script>

<style scoped>
.series-results {
  max-height: 420px;
  overflow-y: auto;
  border-top: 1px solid var(--md-sys-color-outline-variant);
}
</style>
