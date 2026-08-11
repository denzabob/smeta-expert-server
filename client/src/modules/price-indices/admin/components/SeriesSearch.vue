<template>
  <SectionCard variant="outlined">
    <div class="text-title-medium font-weight-medium mb-1">Поиск товара и ряда</div>
    <div class="text-body-2 text-medium-emphasis mb-3">Поиск выполняется по коду или наименованию внутри выбранной версии.</div>
    <v-text-field :model-value="query" label="Код или наименование товара" placeholder="31.02 или кухонной мебели"
      prepend-inner-icon="mdi-magnify" clearable density="compact" variant="outlined" hide-details
      :disabled="disabled" :loading="loading" @update:model-value="$emit('update:query', $event ?? '')" />
    <v-alert v-if="!query.trim()" type="info" variant="tonal" density="compact" class="mt-3">Введите код или минимум 2 символа наименования.</v-alert>
    <v-alert v-else-if="!loading && searched && !items.length" type="info" variant="tonal" density="compact" class="mt-3">По вашему запросу series не найдены.</v-alert>
    <v-list v-if="items.length" class="mt-2 pa-0" lines="three" aria-label="Результаты поиска series">
      <v-list-item v-for="item in items" :key="item.public_id" rounded="lg" tabindex="0"
        :active="selectedId === item.public_id" color="primary" @click="$emit('select', item)"
        @keydown.enter.prevent="$emit('select', item)">
        <template #title><span class="font-weight-medium">{{ item.classifier_item.item_code }}</span><v-chip
          v-if="item.classifier_item.provider_code_kind === 'rosstat_local_ag'" size="x-small" variant="tonal" class="ml-2">локальный код Росстата</v-chip></template>
        <template #subtitle><div>{{ item.classifier_item.item_name }}</div><div>{{ item.territory.code }} · {{ item.comparison_basis }} · {{ item.frequency }} · {{ item.unit }}</div></template>
        <template #append><v-icon icon="mdi-chevron-right" /></template>
      </v-list-item>
    </v-list>
    <div v-if="total > items.length" class="d-flex justify-center mt-3">
      <v-btn variant="tonal" color="primary" :loading="loading" @click="$emit('loadMore')">Показать ещё</v-btn>
    </div>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import type { StatisticalSeriesAdmin } from '../types'
defineProps<{ query: string; items: StatisticalSeriesAdmin[]; total: number; loading: boolean; searched: boolean; disabled: boolean; selectedId?: string }>()
defineEmits<{ 'update:query': [value: string]; select: [item: StatisticalSeriesAdmin]; loadMore: [] }>()
</script>
