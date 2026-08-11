<template>
  <PageContainer max-width="1440px">
    <PageHeader title="Данные" subtitle="Проверка опубликованных статистических рядов и происхождения значений." />
    <v-alert v-if="pageError" type="error" variant="tonal" closable class="mb-4" @click:close="pageError = ''">{{ pageError }}</v-alert>
    <DataVersionSelector class="mb-4" :datasets="datasets" :imports="versions" :dataset-id="selectedDatasetId"
      :import-id="selectedImport?.public_id ?? ''" :selected-import="selectedImport" :is-current="isCurrentVersion"
      :loading-datasets="contextLoading" :loading-imports="versionsLoading"
      @update:dataset-id="changeDataset" @update:import-id="changeImport" />

    <v-alert v-if="selectedImport && !isCurrentVersion" type="warning" variant="tonal" class="mb-4">
      <strong>Вы просматриваете историческую версию данных.</strong> Она не используется для новых расчётов.
    </v-alert>
    <v-alert v-if="!contextLoading && selectedDatasetId && !activeImportId && !selectedImport" type="info" variant="tonal" class="mb-4">
      Для выбранного набора данных нет опубликованной версии. Историческую версию можно выбрать вручную, если она доступна.
    </v-alert>

    <template v-if="selectedImport">
      <SeriesSearch class="mb-4" :query="searchQuery" :items="seriesResults" :total="seriesTotal"
        :loading="searchLoading" :searched="searchPerformed" :disabled="!selectedImport"
        :selected-id="selectedSeries?.public_id" @update:query="onSearchInput" @select="selectSeries"
        @load-more="loadMoreSeries" />
      <template v-if="selectedSeries">
        <SelectedSeriesCard :item="selectedSeries" class="mb-4" />
        <ObservationPeriodFilter class="mb-4" :from="periodFrom" :to="periodTo"
          :min="toInputMonth(selectedSeries.period.from)" :max="toInputMonth(selectedSeries.period.to)"
          :loading="observationsLoading" @update:from="periodFrom = $event" @update:to="periodTo = $event"
          @apply="applyPeriod" />
        <ContinuityStatus :diagnostic="continuity" class="mb-4" />
        <ObservationsTable :items="observations" :total="observationsTotal" :page="observationsPage"
          :per-page="observationsPerPage" :loading="observationsLoading" @detail="openObservation"
          @update:page="observationsPage = $event; loadObservations()"
          @update:per-page="observationsPerPage = $event; observationsPage = 1; loadObservations()" />
      </template>
      <v-alert v-else-if="!searchQuery.trim()" type="info" variant="tonal">Найдите товар и выберите конкретную series, чтобы увидеть наблюдения.</v-alert>
    </template>

    <ObservationDetailDrawer v-model="drawerOpen" :observation="selectedObservation" :series="selectedSeries" :selected-import="selectedImport" />
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">{{ snackbar.text }}</v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import ContinuityStatus from '../components/ContinuityStatus.vue'
import DataVersionSelector from '../components/DataVersionSelector.vue'
import ObservationDetailDrawer from '../components/ObservationDetailDrawer.vue'
import ObservationPeriodFilter from '../components/ObservationPeriodFilter.vue'
import ObservationsTable from '../components/ObservationsTable.vue'
import SelectedSeriesCard from '../components/SelectedSeriesCard.vue'
import SeriesSearch from '../components/SeriesSearch.vue'
import { adminPriceIndicesApi } from '../api/adminPriceIndicesApi'
import { analyzeMonthlySeriesContinuity, createLatestRequestGuard, resolveSeriesSearchFilters, toApiMonth, toInputMonth } from '../dataExplorer'
import { getPriceIndicesErrorMessage } from '../errors'
import type { ContinuityDiagnostic, StatisticalDataset, StatisticalImport, StatisticalObservation, StatisticalSeriesAdmin } from '../types'

const route = useRoute(); const router = useRouter()
const datasets = ref<StatisticalDataset[]>([]); const versions = ref<StatisticalImport[]>([])
const selectedDatasetId = ref(''); const selectedImport = ref<StatisticalImport | null>(null); const activeImportId = ref('')
const contextLoading = ref(true); const versionsLoading = ref(false); const pageError = ref('')
const searchQuery = ref(''); const seriesResults = ref<StatisticalSeriesAdmin[]>([]); const seriesTotal = ref(0)
const seriesPage = ref(1); const searchLoading = ref(false); const searchPerformed = ref(false)
const selectedSeries = ref<StatisticalSeriesAdmin | null>(null)
const periodFrom = ref(''); const periodTo = ref(''); const observations = ref<StatisticalObservation[]>([])
const observationsTotal = ref(0); const observationsPage = ref(1); const observationsPerPage = ref(100); const observationsLoading = ref(false)
const selectedObservation = ref<StatisticalObservation | null>(null); const drawerOpen = ref(false)
const snackbar = ref({ show: false, text: '', color: 'success' })
const searchGuard = createLatestRequestGuard(); const observationGuard = createLatestRequestGuard()
let debounceTimer: ReturnType<typeof setTimeout> | undefined

const isCurrentVersion = computed(() => Boolean(selectedImport.value && selectedImport.value.public_id === activeImportId.value))
const continuity = computed<ContinuityDiagnostic | null>(() => {
  if (!selectedSeries.value || !periodFrom.value || !periodTo.value || observationsPage.value !== 1 || observationsTotal.value !== observations.value.length) return null
  return analyzeMonthlySeriesContinuity(observations.value, periodFrom.value, periodTo.value)
})

onMounted(initialize)
onBeforeUnmount(() => { if (debounceTimer) clearTimeout(debounceTimer); searchGuard.invalidate(); observationGuard.invalidate() })

async function initialize() {
  contextLoading.value = true
  try {
    datasets.value = (await adminPriceIndicesApi.listDatasets()).data
    const importQuery = queryString('import')
    if (importQuery) {
      const imported = (await adminPriceIndicesApi.getImport(importQuery)).data
      if (!['published', 'superseded'].includes(imported.status)) throw new Error('selected_import_not_published')
      if (!datasets.value.some((item) => item.public_id === imported.dataset.public_id)) throw new Error('selected_import_dataset_unavailable')
      selectedDatasetId.value = imported.dataset.public_id
      await loadVersions(imported)
    } else {
      const datasetQuery = queryString('dataset')
      selectedDatasetId.value = datasets.value.some((item) => item.public_id === datasetQuery) ? datasetQuery : (datasets.value[0]?.public_id ?? '')
      if (selectedDatasetId.value) await loadVersions()
    }
    if (selectedImport.value && queryString('code')) await recoverSeriesFromQuery()
  } catch (error) {
    pageError.value = error instanceof Error && error.message === 'selected_import_not_published'
      ? 'Выбранный импорт не является опубликованной версией данных.'
      : getPriceIndicesErrorMessage(error, 'Не удалось загрузить контекст Data Explorer.')
  } finally { contextLoading.value = false }
}

async function loadVersions(preselected?: StatisticalImport) {
  if (!selectedDatasetId.value) return
  resetDataContext(); versionsLoading.value = true
  try {
    const [activeResponse, publishedResponse, supersededResponse] = await Promise.all([
      adminPriceIndicesApi.getActiveImport(selectedDatasetId.value),
      adminPriceIndicesApi.listImports({ dataset_public_id: selectedDatasetId.value, status: 'published', per_page: 500, sort: 'published_at', direction: 'desc' }),
      adminPriceIndicesApi.listImports({ dataset_public_id: selectedDatasetId.value, status: 'superseded', per_page: 500, sort: 'published_at', direction: 'desc' }),
    ])
    activeImportId.value = activeResponse.data?.public_id ?? ''
    const all = [...publishedResponse.data, ...supersededResponse.data, ...(preselected ? [preselected] : [])]
    versions.value = [...new Map(all.map((item) => [item.public_id, item])).values()]
    selectedImport.value = preselected ?? versions.value.find((item) => item.public_id === activeImportId.value) ?? null
    if (selectedImport.value) await syncQuery({ dataset: selectedDatasetId.value, import: selectedImport.value.public_id })
  } catch (error) { pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось загрузить опубликованные версии.') }
  finally { versionsLoading.value = false }
}

async function changeDataset(value: string) {
  if (value === selectedDatasetId.value) return
  selectedDatasetId.value = value; pageError.value = ''
  await syncQuery({ dataset: value, import: undefined, code: undefined, from: undefined, to: undefined })
  await loadVersions()
}

async function changeImport(value: string) {
  const next = versions.value.find((item) => item.public_id === value)
  if (!next || next.public_id === selectedImport.value?.public_id) return
  resetDataContext(); selectedImport.value = next
  await syncQuery({ dataset: selectedDatasetId.value, import: next.public_id, code: undefined, from: undefined, to: undefined })
}

function resetDataContext() {
  if (debounceTimer) clearTimeout(debounceTimer)
  searchGuard.invalidate(); observationGuard.invalidate(); searchQuery.value = ''; seriesResults.value = []; seriesTotal.value = 0
  searchPerformed.value = false; selectedSeries.value = null; observations.value = []; observationsTotal.value = 0
  selectedObservation.value = null; drawerOpen.value = false; periodFrom.value = ''; periodTo.value = ''
}

function onSearchInput(value: string) {
  searchQuery.value = value; selectedSeries.value = null; observations.value = []; observationsTotal.value = 0
  selectedObservation.value = null; drawerOpen.value = false
  void syncQuery({ code: undefined, from: undefined, to: undefined })
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => { void performSearch(false, false) }, 300)
}

async function performSearch(exactCode: boolean, append: boolean): Promise<StatisticalSeriesAdmin[]> {
  if (!selectedImport.value) return []
  const filters = resolveSeriesSearchFilters(searchQuery.value, exactCode)
  if (!filters) { seriesResults.value = []; seriesTotal.value = 0; searchPerformed.value = false; return [] }
  const token = searchGuard.next(); const importId = selectedImport.value.public_id
  const page = append ? seriesPage.value + 1 : 1; searchLoading.value = true
  try {
    const response = await adminPriceIndicesApi.listImportSeries(importId, { ...filters, page })
    if (!searchGuard.isCurrent(token) || selectedImport.value?.public_id !== importId) return []
    seriesPage.value = page; seriesResults.value = append ? [...seriesResults.value, ...response.data] : response.data
    seriesTotal.value = response.meta.total; searchPerformed.value = true
    return response.data
  } catch (error) {
    if (searchGuard.isCurrent(token)) pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось выполнить поиск series.')
    return []
  } finally { if (searchGuard.isCurrent(token)) searchLoading.value = false }
}

async function loadMoreSeries() { await performSearch(false, true) }

async function selectSeries(item: StatisticalSeriesAdmin, recoveredFrom = '', recoveredTo = '') {
  observationGuard.invalidate(); selectedSeries.value = item; observations.value = []; observationsTotal.value = 0; observationsPage.value = 1
  searchQuery.value = item.classifier_item.item_code
  periodFrom.value = validRecoveredMonth(recoveredFrom, item.period.from, item.period.to) || toInputMonth(item.period.from)
  periodTo.value = validRecoveredMonth(recoveredTo, item.period.from, item.period.to) || toInputMonth(item.period.to)
  observationsPerPage.value = item.period.observations_count <= 120 ? 120 : 100
  await syncQuery({ dataset: selectedDatasetId.value, import: selectedImport.value?.public_id, code: item.classifier_item.item_code, from: periodFrom.value, to: periodTo.value })
  await loadObservations()
}

async function recoverSeriesFromQuery() {
  searchQuery.value = queryString('code')
  const matches = await performSearch(true, false)
  if (matches.length === 1) await selectSeries(matches[0]!, queryString('from'), queryString('to'))
}

async function applyPeriod() {
  if (!selectedSeries.value || periodFrom.value > periodTo.value) return
  observationsPage.value = 1
  await syncQuery({ from: periodFrom.value, to: periodTo.value })
  await loadObservations()
}

async function loadObservations() {
  if (!selectedImport.value || !selectedSeries.value) return
  const token = observationGuard.next(); const importId = selectedImport.value.public_id; const seriesId = selectedSeries.value.public_id
  observationsLoading.value = true
  try {
    const response = await adminPriceIndicesApi.getImportObservations(importId, {
      series_public_id: seriesId, period_from: toApiMonth(periodFrom.value), period_to: toApiMonth(periodTo.value),
      page: observationsPage.value, per_page: observationsPerPage.value, sort: 'period_start', direction: 'asc',
    })
    if (!observationGuard.isCurrent(token) || selectedImport.value?.public_id !== importId || selectedSeries.value?.public_id !== seriesId) return
    observations.value = response.data; observationsTotal.value = response.meta.total
  } catch (error) { if (observationGuard.isCurrent(token)) pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось загрузить наблюдения.') }
  finally { if (observationGuard.isCurrent(token)) observationsLoading.value = false }
}

function openObservation(item: StatisticalObservation) { selectedObservation.value = item; drawerOpen.value = true }
function queryString(key: string) { const value = route.query[key]; return typeof value === 'string' ? value : '' }
function validRecoveredMonth(value: string, min: string, max: string) { const month = /^\d{4}-\d{2}$/.test(value) ? value : ''; return month && month >= min.slice(0, 7) && month <= max.slice(0, 7) ? month : '' }
async function syncQuery(values: Record<string, string | undefined>) { const query = { ...route.query }; for (const [key, value] of Object.entries(values)) { if (value) query[key] = value; else delete query[key] } await router.replace({ query }) }
</script>
