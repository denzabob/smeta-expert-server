<template>
  <SectionCard class="labor-evidence-panel">
    <template #title>Источники обоснования труда</template>

    <div class="panel-head">
      <div class="panel-copy">
        <div class="panel-copy__title">Источники обоснования труда для проекта</div>
        <div class="panel-copy__text">
          В отчёт попадут только источники, явно привязанные к этому проекту.
        </div>
      </div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-link-plus" @click="openAttachDialog">
        Добавить источники
      </v-btn>
    </div>

    <v-alert
      v-if="!loading && attachedSources.length === 0"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      Для проекта пока не привязаны источники труда, поэтому блок обоснования не будет полным.
    </v-alert>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

    <div v-if="!loading && attachedSources.length === 0" class="empty-wrap">
      <EmptyState
        icon="mdi-briefcase-search-outline"
        title="Для проекта не выбраны источники труда"
        description="Привяжите вакансии или другие источники, чтобы labor evidence попал в Evidence Run и PDF."
      >
        <template #actions>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-link-plus" @click="openAttachDialog">
            Выбрать источники
          </v-btn>
        </template>
      </EmptyState>
    </div>

    <v-data-table
      v-else-if="!loading"
      :headers="headers"
      :items="attachedSources"
      item-value="id"
      density="compact"
      class="sources-table"
    >
      <template #[`item.vacancy_title`]="{ item }">
        <div class="source-title-cell">
          <div class="source-title-head">
            <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
            <v-btn
              v-if="item.source_url"
              :href="item.source_url"
              target="_blank"
              rel="noopener noreferrer"
              icon
              size="x-small"
              variant="text"
              color="primary"
              title="Открыть источник"
            >
              <v-icon size="16">mdi-open-in-new</v-icon>
            </v-btn>
          </div>
          <div v-if="item.employer_name" class="source-title-cell__meta">{{ item.employer_name }}</div>
          <div class="source-title-cell__domain">{{ laborSourceDomain(item) }}</div>
          <div v-if="laborDescriptionPreview(item)" class="source-title-cell__description">{{ laborDescriptionPreview(item) }}</div>
        </div>
      </template>

      <template #[`item.provider`]="{ item }">
        <div class="provider-cell">
          <div class="provider-cell__title">{{ item.provider?.title || laborSourceDomain(item) }}</div>
          <div v-if="item.employer_name" class="provider-cell__meta">{{ item.employer_name }}</div>
        </div>
      </template>

      <template #[`item.rate`]="{ item }">
        <div class="rate-cell">
          <div class="rate-cell__value">{{ laborSourceRateLabel(item) }}</div>
          <v-chip
            v-if="laborSalaryTypeLabel(item)"
            size="x-small"
            variant="tonal"
            color="primary"
          >
            {{ laborSalaryTypeLabel(item) }}
          </v-chip>
        </div>
      </template>

      <template #[`item.assets`]="{ item }">
        <v-chip size="x-small" variant="tonal" :color="hasScreenshot(item) ? 'success' : 'grey'">
          {{ hasScreenshot(item) ? 'Скриншот' : 'Нет' }}
        </v-chip>
      </template>

      <template #[`item.actions`]="{ item }">
        <div class="d-flex ga-2 justify-end">
          <v-btn size="small" variant="text" color="primary" @click="openDetails(item)">
            Детали
          </v-btn>
          <v-btn size="small" variant="text" color="error" @click="detach(item)">
            Убрать
          </v-btn>
        </div>
      </template>
    </v-data-table>

    <v-dialog v-model="attachDialog" max-width="1040" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center justify-space-between">
          <span>Добавить источники труда</span>
          <v-btn icon variant="text" @click="attachDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-5">
          <v-text-field
            v-model="attachSearch"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            placeholder="Поиск по вакансии, работодателю, провайдеру"
            class="mb-4"
          />

          <v-data-table
            :headers="attachHeaders"
            :items="filteredAvailableSources"
            item-value="id"
            density="compact"
          >
            <template #[`item.select`]="{ item }">
              <v-checkbox-btn
                :model-value="selectedSourceIds.includes(item.id)"
                @update:model-value="toggleSelection(item.id)"
              />
            </template>

            <template #[`item.vacancy_title`]="{ item }">
              <div class="source-title-cell">
                <div class="source-title-head">
                  <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
                  <v-btn
                    v-if="item.source_url"
                    :href="item.source_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    icon
                    size="x-small"
                    variant="text"
                    color="primary"
                    title="Открыть источник"
                  >
                    <v-icon size="16">mdi-open-in-new</v-icon>
                  </v-btn>
                </div>
                <div v-if="item.employer_name" class="source-title-cell__meta">{{ item.employer_name }}</div>
                <div class="source-title-cell__domain">{{ laborSourceDomain(item) }}</div>
              </div>
            </template>

            <template #[`item.provider`]="{ item }">
              {{ item.provider?.title || '—' }}
            </template>

            <template #[`item.rate`]="{ item }">
              {{ laborSourceRateLabel(item) }}
            </template>

            <template #[`item.actions`]="{ item }">
              <v-btn size="small" variant="text" color="primary" @click="openDetails(item)">
                Просмотр
              </v-btn>
            </template>
          </v-data-table>
        </v-card-text>
        <v-card-actions class="px-5 pb-5">
          <v-spacer />
          <v-btn variant="text" @click="attachDialog = false">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="selectedSourceIds.length === 0"
            :loading="submitting"
            @click="attachSelected"
          >
            Привязать выбранные
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <LaborEvidenceDetailsDialog
      v-model="detailsDialog"
      :source="detailsSource"
    />
  </SectionCard>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import LaborEvidenceDetailsDialog from '@/components/pricing/LaborEvidenceDetailsDialog.vue'
import {
  laborDescriptionPreview,
  laborEvidenceApi,
  laborEvidenceRecordOf,
  laborSalaryTypeLabel,
  laborSourceDomain,
  laborSourceRateLabel,
  type LaborEvidenceSource,
} from '@/api/laborEvidence'

const props = defineProps<{
  projectId: number | string
}>()

const emit = defineEmits<{
  (e: 'sources-changed'): void
}>()

const loading = ref(false)
const submitting = ref(false)
const attachDialog = ref(false)
const attachSearch = ref('')
const detailsDialog = ref(false)
const detailsSource = ref<LaborEvidenceSource | null>(null)
const attachedSources = ref<LaborEvidenceSource[]>([])
const availableSources = ref<LaborEvidenceSource[]>([])
const selectedSourceIds = ref<number[]>([])

const headers = [
  { title: 'Вакансия', key: 'vacancy_title', sortable: false },
  { title: 'Провайдер', key: 'provider', sortable: false },
  { title: 'Регион', key: 'region.name', sortable: false },
  { title: 'Ставка', key: 'rate', sortable: false, align: 'end' as const },
  { title: 'Подтверждение', key: 'assets', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const attachHeaders = [
  { title: '', key: 'select', sortable: false, width: 48 },
  { title: 'Вакансия', key: 'vacancy_title', sortable: false },
  { title: 'Провайдер', key: 'provider', sortable: false },
  { title: 'Ставка', key: 'rate', sortable: false, align: 'end' as const },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const filteredAvailableSources = computed(() => {
  const query = attachSearch.value.trim().toLowerCase()
  if (!query) return availableSources.value

  return availableSources.value.filter((item) => {
    return [
      item.vacancy_title,
      item.source_title,
      item.employer_name,
      item.provider?.title,
    ].some(value => String(value || '').toLowerCase().includes(query))
  })
})

onMounted(() => {
  void Promise.all([loadAttachedSources(), loadAvailableSources()])
})

async function loadAttachedSources() {
  loading.value = true
  try {
    attachedSources.value = await laborEvidenceApi.getProjectSources(props.projectId)
  } finally {
    loading.value = false
  }
}

async function loadAvailableSources() {
  const response = await laborEvidenceApi.listSources({ per_page: 100 })
  availableSources.value = response.data || []
}

function hasScreenshot(source: LaborEvidenceSource): boolean {
  return (laborEvidenceRecordOf(source)?.assets || []).some(asset => asset.asset_type === 'screenshot')
}

function openAttachDialog() {
  selectedSourceIds.value = attachedSources.value.map(item => item.id)
  attachDialog.value = true
}

function toggleSelection(sourceId: number) {
  if (selectedSourceIds.value.includes(sourceId)) {
    selectedSourceIds.value = selectedSourceIds.value.filter(id => id !== sourceId)
    return
  }

  selectedSourceIds.value = [...selectedSourceIds.value, sourceId]
}

async function attachSelected() {
  submitting.value = true
  try {
    attachedSources.value = await laborEvidenceApi.attachProjectSources(props.projectId, selectedSourceIds.value)
    attachDialog.value = false
    emit('sources-changed')
  } finally {
    submitting.value = false
  }
}

async function detach(source: LaborEvidenceSource) {
  submitting.value = true
  try {
    attachedSources.value = await laborEvidenceApi.detachProjectSources(props.projectId, [source.id])
    selectedSourceIds.value = selectedSourceIds.value.filter(id => id !== source.id)
    emit('sources-changed')
  } finally {
    submitting.value = false
  }
}

function openDetails(source: LaborEvidenceSource) {
  detailsSource.value = source
  detailsDialog.value = true
}
</script>

<style scoped>
.panel-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 16px;
}

.panel-copy__title {
  font-size: 15px;
  font-weight: 600;
}

.panel-copy__text {
  margin-top: 4px;
  color: rgba(0, 0, 0, 0.6);
}

.empty-wrap {
  padding: 8px 0 4px;
}

.source-title-cell__title {
  font-weight: 600;
  line-height: 1.35;
}

.source-title-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
}

.source-title-cell__meta {
  margin-top: 2px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.56);
}

.source-title-cell__domain {
  margin-top: 2px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.48);
}

.source-title-cell__description {
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.66);
  white-space: pre-wrap;
}

.provider-cell {
  display: grid;
  gap: 2px;
}

.provider-cell__title {
  font-weight: 500;
}

.provider-cell__meta {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.56);
}

.rate-cell {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}

.rate-cell__value {
  font-weight: 600;
  text-align: right;
}

@media (max-width: 900px) {
  .panel-head {
    flex-direction: column;
  }
}
</style>
