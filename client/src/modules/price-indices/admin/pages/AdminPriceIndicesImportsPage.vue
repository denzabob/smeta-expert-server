<template>
  <PageContainer max-width="1440px">
    <PageHeader title="Импорты XLSX" subtitle="История обработки, диагностика и публикация статистических данных." />
    <v-alert v-if="pageError" type="error" variant="tonal" closable class="mb-4" @click:close="pageError = ''">{{ pageError }}</v-alert>
    <SectionCard variant="outlined" class="mb-4">
      <v-row dense align="center">
        <v-col cols="12" md="4"><v-select v-model="filters.dataset" :items="datasets" item-title="name" item-value="public_id" label="Набор данных" clearable density="compact" variant="outlined" hide-details /></v-col>
        <v-col cols="12" sm="6" md="3"><v-select v-model="filters.status" :items="statusOptions" label="Статус" clearable density="compact" variant="outlined" hide-details /></v-col>
        <v-col cols="12" sm="6" md="2"><v-text-field v-model="filters.importerVersion" label="Версия импортера" clearable density="compact" variant="outlined" hide-details /></v-col>
        <v-col cols="6" md="1"><v-text-field v-model="filters.from" type="date" label="С" density="compact" variant="outlined" hide-details /></v-col>
        <v-col cols="6" md="1"><v-text-field v-model="filters.to" type="date" label="По" density="compact" variant="outlined" hide-details /></v-col>
        <v-col cols="12" md="1" class="text-right"><v-btn icon="mdi-refresh" variant="tonal" color="primary" :loading="loading" title="Обновить" @click="applyFilters" /></v-col>
      </v-row>
    </SectionCard>
    <ImportsTable :items="imports" :total="total" :page="page" :per-page="perPage" :loading="loading"
      @open="openDetail" @update:page="page = $event; loadImports()" @update:per-page="perPage = $event; page = 1; loadImports()" />

    <v-dialog v-model="detailDialog" max-width="1000" scrollable>
      <v-card><v-card-title class="d-flex align-center">Импорт XLSX<v-spacer /><v-btn icon="mdi-close" variant="text" @click="closeDetail" /></v-card-title>
        <v-card-text><ImportStatusPanel :item="selectedImport" :busy="actionBusy" @issues="openIssues" @retry="retryImport" @publish="publishDialog = true" /></v-card-text>
      </v-card>
    </v-dialog>
    <v-dialog v-model="publishDialog" max-width="560" persistent><v-card><v-card-title>Опубликовать импорт?</v-card-title><v-card-text>Импорт станет текущей официальной публикацией. Предыдущая версия останется в истории.</v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="publishDialog = false">Отмена</v-btn><v-btn color="primary" :loading="actionBusy" @click="publishImport">Опубликовать</v-btn></v-card-actions></v-card></v-dialog>
    <ImportIssuesTable v-model="issuesDialog" :items="issues" :total="issuesTotal" :page="issuesPage" :per-page="issuesPerPage" :loading="issuesLoading"
      @update:page="issuesPage = $event; loadIssues()" @update:per-page="issuesPerPage = $event; issuesPage = 1; loadIssues()" />
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">{{ snackbar.text }}</v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import ImportsTable from '../components/ImportsTable.vue'
import ImportStatusPanel from '../components/ImportStatusPanel.vue'
import ImportIssuesTable from '../components/ImportIssuesTable.vue'
import { adminPriceIndicesApi } from '../api/adminPriceIndicesApi'
import { usePollingTask } from '../composables/usePollingTask'
import { getPriceIndicesErrorMessage, isPublicationConflict } from '../errors'
import { importStatusLabels } from '../status'
import type { ImportStatus, StatisticalDataset, StatisticalImport, StatisticalImportIssue } from '../types'

const route = useRoute(); const router = useRouter()
const datasets = ref<StatisticalDataset[]>([]); const imports = ref<StatisticalImport[]>([]); const total = ref(0); const page = ref(1); const perPage = ref(25); const loading = ref(false); const pageError = ref('')
const filters = reactive({ dataset: '', status: '' as ImportStatus | '', importerVersion: '', from: '', to: '' })
const selectedImport = ref<StatisticalImport | null>(null); const detailDialog = ref(false); const actionBusy = ref(false); const publishDialog = ref(false)
const issuesDialog = ref(false); const issues = ref<StatisticalImportIssue[]>([]); const issuesTotal = ref(0); const issuesPage = ref(1); const issuesPerPage = ref(50); const issuesLoading = ref(false)
const snackbar = ref({ show: false, text: '', color: 'success' })
const statusOptions = Object.entries(importStatusLabels).map(([value, title]) => ({ value, title }))
const detailPoller = usePollingTask({
  fetcher: async () => { if (!selectedImport.value) throw new Error('Import is not selected'); return (await adminPriceIndicesApi.getImport(selectedImport.value.public_id)).data },
  isTerminal: (value) => ['ready_for_publish', 'published', 'superseded', 'failed'].includes(value.status),
  onData: (value) => { selectedImport.value = value },
  onError: () => { pageError.value = 'Временная ошибка обновления импорта. Повторная попытка будет выполнена автоматически.' },
  intervalMs: 2500, timeoutMs: 30 * 60 * 1000,
})

onMounted(async () => {
  const query = route.query
  filters.dataset = typeof query.dataset === 'string' ? query.dataset : ''
  filters.status = typeof query.status === 'string' && query.status in importStatusLabels ? query.status as ImportStatus : ''
  filters.importerVersion = typeof query.importer_version === 'string' ? query.importer_version : ''
  filters.from = typeof query.from === 'string' ? query.from : ''; filters.to = typeof query.to === 'string' ? query.to : ''
  try { datasets.value = (await adminPriceIndicesApi.listDatasets()).data; await loadImports() }
  catch (error) { pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось загрузить историю импортов.') }
})
watch(() => filters.dataset, applyFilters); watch(() => filters.status, applyFilters)

function notify(text: string, color = 'success') { snackbar.value = { show: true, text, color } }
async function syncQuery() { const query: Record<string, string> = {}; if (filters.dataset) query.dataset = filters.dataset; if (filters.status) query.status = filters.status; if (filters.importerVersion) query.importer_version = filters.importerVersion; if (filters.from) query.from = filters.from; if (filters.to) query.to = filters.to; await router.replace({ query }) }
async function applyFilters() { page.value = 1; await syncQuery(); await loadImports() }
async function loadImports() { loading.value = true; try { const response = await adminPriceIndicesApi.listImports({ dataset_public_id: filters.dataset || undefined, status: filters.status || undefined, importer_version: filters.importerVersion || undefined, created_from: filters.from || undefined, created_to: filters.to || undefined, page: page.value, per_page: perPage.value, sort: 'created_at', direction: 'desc' }); imports.value = response.data; total.value = response.meta.total } catch (error) { pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось загрузить историю импортов.') } finally { loading.value = false } }
async function openDetail(item: StatisticalImport) { detailDialog.value = true; try { selectedImport.value = (await adminPriceIndicesApi.getImport(item.public_id)).data; if (['pending', 'importing', 'validating'].includes(selectedImport.value.status)) detailPoller.start() } catch (error) { pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось открыть импорт.') } }
function closeDetail() { detailPoller.stop(); detailDialog.value = false }
async function retryImport() { if (!selectedImport.value || actionBusy.value) return; actionBusy.value = true; try { selectedImport.value = (await adminPriceIndicesApi.retryImport(selectedImport.value.public_id)).data; detailPoller.start(); notify('Повторный импорт поставлен в очередь.'); await loadImports() } catch (error) { notify(getPriceIndicesErrorMessage(error, 'Не удалось повторить импорт.'), 'error') } finally { actionBusy.value = false } }
async function publishImport() { if (!selectedImport.value || actionBusy.value) return; actionBusy.value = true; try { selectedImport.value = (await adminPriceIndicesApi.publishImport(selectedImport.value.public_id)).data; publishDialog.value = false; notify('Импорт опубликован.'); await loadImports() } catch (error) { notify(getPriceIndicesErrorMessage(error, 'Не удалось опубликовать импорт.'), 'error'); if (isPublicationConflict(error)) selectedImport.value = (await adminPriceIndicesApi.getImport(selectedImport.value.public_id)).data } finally { actionBusy.value = false } }
async function openIssues() { issuesDialog.value = true; issuesPage.value = 1; await loadIssues() }
async function loadIssues() { if (!selectedImport.value) return; issuesLoading.value = true; try { const response = await adminPriceIndicesApi.getImportIssues(selectedImport.value.public_id, issuesPage.value, issuesPerPage.value); issues.value = response.data; issuesTotal.value = response.meta.total } catch (error) { notify(getPriceIndicesErrorMessage(error, 'Не удалось загрузить проблемы импорта.'), 'error') } finally { issuesLoading.value = false } }
</script>
