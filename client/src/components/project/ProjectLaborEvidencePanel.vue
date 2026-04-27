<template>
  <SectionCard class="labor-evidence-panel">
    <div class="panel-head">
      <div class="panel-copy">
        <div class="panel-copy__title">Источники обоснования труда</div>
      </div>
      <v-btn color="primary" variant="tonal" prepend-icon="mdi-link-plus" @click="openAttachDialog">
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
          <v-btn color="primary" variant="tonal" prepend-icon="mdi-link-plus" @click="openAttachDialog">
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
        <div class="panel-actions">
          <v-btn
            icon="mdi-open-in-new"
            size="x-small"
            variant="text"
            color="primary"
            title="Открыть детали"
            @click="openDetails(item)"
          />
          <v-btn
            icon="mdi-link-off"
            size="x-small"
            variant="text"
            color="error"
            title="Убрать источник"
            @click="detach(item)"
          />
        </div>
      </template>
    </v-data-table>

    <v-dialog v-model="attachDialog" max-width="1040" scrollable class="labor-attach-dialog">
      <v-card class="labor-attach-dialog-card">
        <v-card-title class="labor-attach-dialog-card__title d-flex align-center justify-space-between">
          <span>Добавить источники труда</span>
          <v-btn icon variant="text" @click="attachDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="labor-attach-dialog-card__content">
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
            class="sources-table"
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
              <div class="panel-actions">
                <v-btn
                  icon="mdi-open-in-new"
                  size="x-small"
                  variant="text"
                  color="primary"
                  title="Просмотр"
                  @click="openDetails(item)"
                />
              </div>
            </template>
          </v-data-table>
        </v-card-text>
        <v-card-actions class="labor-attach-dialog-card__actions">
          <v-spacer />
          <v-btn variant="text" @click="attachDialog = false">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="tonal"
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
  { title: 'Действия', key: 'actions', sortable: false, align: 'center' as const },
]

const attachHeaders = [
  { title: '', key: 'select', sortable: false, width: 48 },
  { title: 'Вакансия', key: 'vacancy_title', sortable: false },
  { title: 'Провайдер', key: 'provider', sortable: false },
  { title: 'Ставка', key: 'rate', sortable: false, align: 'end' as const },
  { title: 'Действия', key: 'actions', sortable: false, align: 'center' as const },
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
.labor-evidence-panel :deep(.md3-section-card__content) {
  padding: 0 !important;
}

.labor-evidence-panel {
  border: 0 !important;
  box-shadow: none !important;
  background: transparent !important;
}

.panel-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  padding: 0 0 14px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
}

.panel-copy {
  min-width: 0;
}

.panel-copy__eyebrow {
  font-size: 0.7rem;
  line-height: 1.2;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface-variant), 0.78);
}

.panel-copy__title {
  margin-top: 4px;
  font-size: 0.95rem;
  line-height: 1.25;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.panel-copy__text {
  margin-top: 4px;
  font-size: 0.8125rem;
  line-height: 1.45;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
}

.empty-wrap {
  padding: 14px 0 0;
}

.sources-table :deep(.v-table__wrapper) {
  border-radius: 0;
  border: 0;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
  background: transparent;
}

.sources-table :deep(thead th) {
  height: 36px !important;
  padding: 0 10px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.34) !important;
  background: rgba(var(--v-theme-surface-container-low), 0.54) !important;
}

.sources-table :deep(thead th .v-data-table-header__content) {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface-variant), 1);
}

.sources-table :deep(tbody td) {
  height: auto !important;
  vertical-align: middle;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.22) !important;
  padding: 6px 10px !important;
  background: transparent !important;
  line-height: 1.25;
}

.sources-table :deep(.v-data-table-footer) {
  min-height: 42px;
  padding: 4px 8px;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
  background: transparent;
}

.sources-table :deep(.v-data-table-footer__items-per-page),
.sources-table :deep(.v-data-table-footer__pagination) {
  align-items: center;
  margin: 0;
}

.sources-table :deep(.v-data-table-footer .v-field) {
  min-height: 30px;
}

.sources-table :deep(.v-data-table-footer .v-field__input) {
  min-height: 30px;
  padding-top: 0;
  padding-bottom: 0;
}

.sources-table :deep(.v-data-table-footer .v-btn--icon) {
  width: 30px;
  height: 30px;
}

.sources-table :deep(.v-table) {
  margin: 14px 0 0;
}

.source-title-cell {
  display: grid;
  gap: 1px;
  min-width: 0;
  padding-block: 1px;
}

.source-title-cell__title {
  font-weight: 600;
  line-height: 1.22;
  color: rgba(var(--v-theme-on-surface), 1);
  overflow-wrap: anywhere;
}

.source-title-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  min-width: 0;
}

.source-title-head :deep(.v-btn) {
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  margin: -2px 0;
}

.source-title-cell__meta {
  font-size: 0.75rem;
  line-height: 1.18;
  color: rgba(var(--v-theme-on-surface-variant), 0.9);
}

.source-title-cell__domain {
  font-size: 0.75rem;
  line-height: 1.18;
  color: rgba(var(--v-theme-on-surface-variant), 0.78);
}

.provider-cell {
  display: grid;
  gap: 1px;
  min-width: 0;
}

.provider-cell__title {
  font-weight: 500;
  line-height: 1.22;
  color: rgba(var(--v-theme-on-surface), 1);
  overflow-wrap: anywhere;
}

.provider-cell__meta {
  font-size: 0.75rem;
  line-height: 1.18;
  color: rgba(var(--v-theme-on-surface-variant), 0.9);
}

.rate-cell {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 4px;
  max-width: 100%;
}

.rate-cell__value {
  font-weight: 600;
  line-height: 1.2;
  text-align: right;
  color: rgba(var(--v-theme-on-surface), 1);
}

.sources-table :deep(tbody td .v-chip) {
  height: 22px;
  min-width: 0;
  padding-inline: 7px;
}

.sources-table :deep(tbody td .v-chip__content) {
  line-height: 1;
}

.panel-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: 60px;
  margin: 0 auto;
}

.panel-actions :deep(.v-btn) {
  width: 30px;
  height: 30px;
  min-width: 30px;
  padding: 0;
}

.labor-attach-dialog-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.98);
  overflow: hidden;
}

.labor-attach-dialog-card__title {
  padding: 16px 18px 12px !important;
  font-size: 1rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.labor-attach-dialog-card__content {
  padding: 18px !important;
}

.labor-attach-dialog-card__actions {
  padding: 0 18px 18px !important;
}

@media (max-width: 900px) {
  .panel-head {
    flex-direction: column;
    padding: 0 0 14px;
  }

  .empty-wrap {
    padding: 14px 0 0;
  }

  .sources-table :deep(.v-table) {
    margin: 14px 0 0;
  }

  .labor-attach-dialog-card__title {
    padding-inline: 14px !important;
  }

  .labor-attach-dialog-card__content,
  .labor-attach-dialog-card__actions {
    padding-inline: 14px !important;
  }
}
</style>
