<template>
  <SectionCard variant="outlined">
    <v-row dense align="center">
      <v-col cols="12" md="6">
        <v-select :model-value="datasetId" :items="datasets" item-value="public_id" :item-title="datasetTitle"
          label="Набор данных" density="compact" variant="outlined" hide-details :loading="loadingDatasets"
          @update:model-value="$emit('update:datasetId', $event)" />
      </v-col>
      <v-col cols="12" md="6">
        <v-select :model-value="importId" :items="imports" item-value="public_id" :item-title="importTitle"
          label="Версия данных" density="compact" variant="outlined" hide-details :loading="loadingImports"
          :disabled="!datasetId || !imports.length" @update:model-value="$emit('update:importId', $event)" />
      </v-col>
    </v-row>
    <div v-if="selectedImport" class="d-flex flex-wrap align-center ga-2 mt-3 text-body-2">
      <v-chip :color="isCurrent ? 'success' : 'warning'" size="small" variant="tonal">
        {{ isCurrent ? 'Текущая версия' : 'Историческая версия' }}
      </v-chip>
      <span>{{ selectedImport.source_file.original_filename }}</span>
      <span class="text-medium-emphasis">{{ selectedImport.importer.code }}@{{ selectedImport.importer.version }}</span>
      <span class="text-medium-emphasis" :title="selectedImport.public_id">{{ shortIdentifier(selectedImport.public_id) }}</span>
      <CopyValueButton :value="selectedImport.public_id" tooltip="Копировать UUID импорта" />
    </div>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
import CopyValueButton from './CopyValueButton.vue'
import { shortIdentifier } from '../dataExplorer'
import type { StatisticalDataset, StatisticalImport } from '../types'
defineProps<{ datasets: StatisticalDataset[]; imports: StatisticalImport[]; datasetId: string; importId: string; selectedImport: StatisticalImport | null; isCurrent: boolean; loadingDatasets: boolean; loadingImports: boolean }>()
defineEmits<{ 'update:datasetId': [value: string]; 'update:importId': [value: string] }>()
function datasetTitle(item: StatisticalDataset | string) { return typeof item === 'string' ? item : `${item.name} · ${item.code}` }
function importTitle(item: StatisticalImport | string) { return typeof item === 'string' ? item : `${item.source_file.original_filename} · ${item.status} · ${shortIdentifier(item.public_id)}` }
</script>
