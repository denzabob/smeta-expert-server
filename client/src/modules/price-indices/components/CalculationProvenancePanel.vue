<template>
  <v-expansion-panels variant="accordion">
    <v-expansion-panel title="Сведения об источнике расчёта">
      <v-expansion-panel-text>
        <div class="provenance-grid">
          <div class="provenance-entry provenance-entry--wide">
            <div class="text-caption text-medium-emphasis">Набор данных</div>
            <div class="text-body-2">{{ provenance.dataset.name }}</div>
          </div>
          <div class="provenance-entry">
            <div class="text-caption text-medium-emphasis">Файл источника</div>
            <div class="text-body-2 text-break">{{ provenance.source_file.original_filename }}</div>
          </div>
          <div class="provenance-entry">
            <div class="text-caption text-medium-emphasis">Опубликовано</div>
            <div class="text-body-2">{{ formatPublishedAt(provenance.import.published_at) }}</div>
          </div>
          <div class="provenance-entry">
            <div class="text-caption text-medium-emphasis">Импортер</div>
            <div class="text-body-2">{{ provenance.import.importer_code }}@{{ provenance.import.importer_version }}</div>
          </div>
          <div class="provenance-entry">
            <div class="text-caption text-medium-emphasis">Версия данных</div>
            <div class="d-flex align-center ga-1">
              <span class="text-body-2">{{ shortIdentifier(provenance.import.public_id) }}</span>
              <CopyValueButton :value="provenance.import.public_id" tooltip="Копировать UUID версии данных" />
            </div>
          </div>
          <div class="provenance-entry provenance-entry--wide">
            <div class="text-caption text-medium-emphasis">SHA-256</div>
            <div class="d-flex align-center ga-1 min-width-0">
              <span class="text-body-2 text-break">{{ shortIdentifier(provenance.source_file.sha256, 16, 12) }}</span>
              <CopyValueButton :value="provenance.source_file.sha256" tooltip="Копировать SHA-256" />
            </div>
          </div>
        </div>
      </v-expansion-panel-text>
    </v-expansion-panel>
  </v-expansion-panels>
</template>

<script setup lang="ts">
import { formatPublishedAt, shortIdentifier } from '../calculator'
import type { StatisticalCalculationProvenance } from '../types'
import CopyValueButton from './CopyValueButton.vue'

defineProps<{ provenance: StatisticalCalculationProvenance }>()
</script>

<style scoped>
.provenance-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--ds-space-14);
}
.provenance-entry--wide { grid-column: 1 / -1; }
.min-width-0 { min-width: 0; }
@media (max-width: 620px) {
  .provenance-grid { grid-template-columns: 1fr; }
  .provenance-entry--wide { grid-column: auto; }
}
</style>
