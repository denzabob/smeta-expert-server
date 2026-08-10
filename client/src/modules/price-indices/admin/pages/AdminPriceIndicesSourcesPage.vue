<template>
  <PageContainer max-width="1440px">
    <PageHeader title="Источники данных" subtitle="Загрузка, проверка и публикация официальных статистических XLSX." />
    <v-alert v-if="pageError" type="error" variant="tonal" closable class="mb-4" @click:close="pageError = ''">{{ pageError }}</v-alert>
    <v-skeleton-loader v-if="initialLoading" type="article, table" />
    <template v-else>
      <SectionCard v-if="datasets.length" variant="outlined" class="mb-4">
        <v-row dense align="center">
          <v-col cols="12" md="6"><v-select v-model="selectedDatasetId" :items="datasets" item-value="public_id"
            :item-title="datasetTitle" label="Набор данных" density="compact" variant="outlined" hide-details /></v-col>
          <v-col cols="12" md="6"><v-select v-model="selectedSourceId" :items="sources" item-value="public_id" item-title="name"
            label="Источник" density="compact" variant="outlined" clearable hide-details :disabled="!sources.length" /></v-col>
        </v-row>
        <v-alert v-if="selectedDatasetId && !sources.length" type="info" variant="tonal" density="compact" class="mt-3">Для набора не настроены источники. Файл можно загрузить без привязки к источнику.</v-alert>
      </SectionCard>
      <v-alert v-else type="info" variant="tonal">Наборы статистических данных отсутствуют. Создайте dataset через существующее административное API.</v-alert>

      <template v-if="selectedDatasetId">
        <ActiveImportCard :item="activeImport" class="mb-4" />
        <SourceFileUploadCard :dataset-public-id="selectedDatasetId" :sources="sources" class="mb-4" @uploaded="onUploaded" @error="notifyError" />
        <SourceFilesTable :items="sourceFiles" :total="sourceFilesTotal" :page="filesPage" :per-page="filesPerPage"
          :loading="filesLoading" :busy-id="busyFileId" class="mb-4" @refresh="loadSourceFiles"
          @update:page="filesPage = $event; loadSourceFiles()" @update:per-page="filesPerPage = $event; filesPage = 1; loadSourceFiles()"
          @approve="approveFile" @reject="openReject" @activate="openActivate" @preview="startPreview" @download="downloadFile" />
        <ImportPreviewPanel :preview="preview" :result="previewResult" :cached="previewCached" :busy="previewBusy"
          class="mb-4" @retry="retryPreview" @start-import="openImportConfirm" />
        <ImportStatusPanel :item="currentImport" :busy="importBusy" class="mb-4" @retry="retryImport" @publish="publishDialog = true" @issues="openIssues" />
      </template>
    </template>

    <v-dialog v-model="rejectDialog" max-width="520" persistent><v-card><v-card-title>Отклонить файл</v-card-title><v-card-text><v-textarea v-model="rejectReason" label="Причина" variant="outlined" rows="3" maxlength="5000" /></v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="rejectDialog = false">Отмена</v-btn><v-btn color="error" :loading="actionBusy" :disabled="!rejectReason.trim()" @click="rejectFile">Отклонить</v-btn></v-card-actions></v-card></v-dialog>
    <v-dialog v-model="activateDialog" max-width="560" persistent><v-card><v-card-title>Активировать исходный файл?</v-card-title><v-card-text>Активировать этот файл как официальный исходный файл для выбранного периода?</v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="activateDialog = false">Отмена</v-btn><v-btn color="primary" :loading="actionBusy" @click="activateFile">Активировать</v-btn></v-card-actions></v-card></v-dialog>
    <v-dialog v-model="importDialog" max-width="560" persistent><v-card><v-card-title>Импортировать данные?</v-card-title><v-card-text>Будет импортировано до <strong>{{ previewResult?.counts.observation_candidates.toLocaleString('ru-RU') }}</strong> наблюдений. Операция выполняется в очереди.</v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="importDialog = false">Отмена</v-btn><v-btn color="primary" :loading="importBusy" @click="startImport">Импортировать</v-btn></v-card-actions></v-card></v-dialog>
    <v-dialog v-model="publishDialog" max-width="560" persistent><v-card><v-card-title>Опубликовать импорт?</v-card-title><v-card-text>Импорт станет текущим источником статистических данных. Предыдущая публикация сохранится в истории.</v-card-text><v-card-actions><v-spacer /><v-btn variant="text" @click="publishDialog = false">Отмена</v-btn><v-btn color="primary" :loading="importBusy" @click="publishImport">Опубликовать</v-btn></v-card-actions></v-card></v-dialog>
    <ImportIssuesTable v-model="issuesDialog" :items="issues" :total="issuesTotal" :page="issuesPage" :per-page="issuesPerPage" :loading="issuesLoading"
      @update:page="issuesPage = $event; loadIssues()" @update:per-page="issuesPerPage = $event; issuesPage = 1; loadIssues()" />
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">{{ snackbar.text }}</v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import ActiveImportCard from '../components/ActiveImportCard.vue'
import ImportIssuesTable from '../components/ImportIssuesTable.vue'
import ImportPreviewPanel from '../components/ImportPreviewPanel.vue'
import ImportStatusPanel from '../components/ImportStatusPanel.vue'
import SourceFilesTable from '../components/SourceFilesTable.vue'
import SourceFileUploadCard from '../components/SourceFileUploadCard.vue'
import { adminPriceIndicesApi } from '../api/adminPriceIndicesApi'
import { getPriceIndicesErrorMessage, isPublicationConflict } from '../errors'
import { usePollingTask } from '../composables/usePollingTask'
import type { StatisticalDataset, StatisticalImport, StatisticalImportIssue, StatisticalImportPreview, StatisticalImportPreviewResult, StatisticalSource, StatisticalSourceFile } from '../types'

const route = useRoute(); const router = useRouter()
const datasets = ref<StatisticalDataset[]>([]); const sources = ref<StatisticalSource[]>([]); const sourceFiles = ref<StatisticalSourceFile[]>([])
const selectedDatasetId = ref(''); const selectedSourceId = ref<string | null>(null)
const sourceFilesTotal = ref(0); const filesPage = ref(1); const filesPerPage = ref(25)
const initialLoading = ref(true); const filesLoading = ref(false); const pageError = ref(''); const busyFileId = ref<string | null>(null); const actionBusy = ref(false)
const preview = ref<StatisticalImportPreview | null>(null); const previewResult = ref<StatisticalImportPreviewResult | null>(null); const previewCached = ref(false); const previewBusy = ref(false)
const currentImport = ref<StatisticalImport | null>(null); const activeImport = ref<StatisticalImport | null>(null); const importBusy = ref(false)
const rejectDialog = ref(false); const rejectReason = ref(''); const actionFile = ref<StatisticalSourceFile | null>(null); const activateDialog = ref(false); const importDialog = ref(false); const publishDialog = ref(false)
const issuesDialog = ref(false); const issues = ref<StatisticalImportIssue[]>([]); const issuesTotal = ref(0); const issuesPage = ref(1); const issuesPerPage = ref(50); const issuesLoading = ref(false)
const snackbar = ref({ show: false, text: '', color: 'success' })

const previewPoller = usePollingTask({
  fetcher: async () => { if (!preview.value) throw new Error('Preview is not selected'); return (await adminPriceIndicesApi.getPreview(preview.value.public_id)).data },
  isTerminal: (value) => ['ready', 'failed', 'expired'].includes(value.status),
  onData: async (value) => { preview.value = value; if (value.status === 'ready') await loadPreviewResult(value.public_id) },
  onError: () => { pageError.value = 'Временная ошибка обновления статуса анализа. Повторная попытка будет выполнена автоматически.' }, intervalMs: 2500, timeoutMs: 15 * 60 * 1000,
})
const importPoller = usePollingTask({
  fetcher: async () => { if (!currentImport.value) throw new Error('Import is not selected'); return (await adminPriceIndicesApi.getImport(currentImport.value.public_id)).data },
  isTerminal: (value) => ['ready_for_publish', 'published', 'superseded', 'failed'].includes(value.status),
  onData: async (value) => { currentImport.value = value; if (value.status === 'published') await loadActiveImport() },
  onError: () => { pageError.value = 'Временная ошибка обновления статуса импорта. Повторная попытка будет выполнена автоматически.' }, intervalMs: 2500, timeoutMs: 30 * 60 * 1000,
})

onMounted(async () => {
  try {
    const response = await adminPriceIndicesApi.listDatasets(); datasets.value = response.data
    const queryDataset = typeof route.query.dataset === 'string' ? route.query.dataset : ''
    selectedDatasetId.value = datasets.value.some((item) => item.public_id === queryDataset) ? queryDataset : (datasets.value[0]?.public_id ?? '')
    if (selectedDatasetId.value) await loadDatasetContext()
    const previewId = typeof route.query.preview === 'string' ? route.query.preview : ''
    if (previewId) await recoverPreview(previewId)
    const importId = typeof route.query.import === 'string' ? route.query.import : ''
    if (importId) await recoverImport(importId)
  } catch (error) { pageError.value = getPriceIndicesErrorMessage(error, 'Не удалось загрузить данные страницы.') }
  finally { initialLoading.value = false }
})
watch(selectedDatasetId, async (value, previous) => { if (!previous || value === previous) return; stopTasks(); selectedSourceId.value = null; filesPage.value = 1; await updateQuery({ dataset: value, preview: undefined, import: undefined }); await loadDatasetContext() })
watch(selectedSourceId, async () => { filesPage.value = 1; await loadSourceFiles() })

function datasetTitle(item: StatisticalDataset) { return `${item.name} · ${item.code}` }
function notify(text: string, color = 'success') { snackbar.value = { show: true, text, color } }
function notifyError(message: string) { notify(message, 'error') }
function stopTasks() { previewPoller.stop(); importPoller.stop(); preview.value = null; previewResult.value = null; currentImport.value = null }
async function updateQuery(values: Record<string, string | undefined>) { const query = { ...route.query }; for (const [key, value] of Object.entries(values)) { if (value) query[key] = value; else delete query[key] } await router.replace({ query }) }
async function loadDatasetContext() { await Promise.all([loadSources(), loadSourceFiles(), loadActiveImport()]) }
async function loadSources() { if (!selectedDatasetId.value) return; sources.value = (await adminPriceIndicesApi.listSources(selectedDatasetId.value)).data }
async function loadSourceFiles() { if (!selectedDatasetId.value) return; filesLoading.value = true; try { const response = await adminPriceIndicesApi.listSourceFiles({ dataset: selectedDatasetId.value, source: selectedSourceId.value || undefined, page: filesPage.value, per_page: filesPerPage.value, sort: 'detected_at', direction: 'desc' }); sourceFiles.value = response.data; sourceFilesTotal.value = response.meta.total } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось загрузить файлы.')) } finally { filesLoading.value = false } }
async function loadActiveImport() { if (!selectedDatasetId.value) return; activeImport.value = (await adminPriceIndicesApi.getActiveImport(selectedDatasetId.value)).data }
async function onUploaded(file: StatisticalSourceFile) { notify(`Файл «${file.original_filename}» загружен.`); await loadSourceFiles() }
async function approveFile(file: StatisticalSourceFile) { if (busyFileId.value) return; busyFileId.value = file.public_id; try { await adminPriceIndicesApi.approveSourceFile(file.public_id); notify('Файл одобрен.'); await loadSourceFiles() } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось одобрить файл.')) } finally { busyFileId.value = null } }
function openReject(file: StatisticalSourceFile) { actionFile.value = file; rejectReason.value = ''; rejectDialog.value = true }
async function rejectFile() { if (!actionFile.value || actionBusy.value) return; actionBusy.value = true; try { await adminPriceIndicesApi.rejectSourceFile(actionFile.value.public_id, rejectReason.value.trim()); rejectDialog.value = false; notify('Файл отклонён.'); await loadSourceFiles() } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось отклонить файл.')) } finally { actionBusy.value = false } }
function openActivate(file: StatisticalSourceFile) { actionFile.value = file; activateDialog.value = true }
async function activateFile() { if (!actionFile.value || actionBusy.value) return; actionBusy.value = true; try { await adminPriceIndicesApi.activateSourceFile(actionFile.value.public_id); activateDialog.value = false; notify('Файл активирован.'); await Promise.all([loadSourceFiles(), loadActiveImport()]) } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось активировать файл.')) } finally { actionBusy.value = false } }
async function downloadFile(file: StatisticalSourceFile) { if (busyFileId.value) return; busyFileId.value = file.public_id; try { const response = await adminPriceIndicesApi.downloadSourceFile(file.public_id); const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = file.original_filename; link.click(); URL.revokeObjectURL(url) } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось скачать файл.')) } finally { busyFileId.value = null } }
async function startPreview(file: StatisticalSourceFile) { if (previewBusy.value) return; previewBusy.value = true; previewPoller.stop(); previewResult.value = null; try { const response = await adminPriceIndicesApi.startPreview(file.public_id); preview.value = response.data; previewCached.value = response.meta.cached; await updateQuery({ preview: response.data.public_id }); if (response.data.status === 'ready') { await loadPreviewResult(response.data.public_id); notify('Использован ранее выполненный анализ.', 'info') } else previewPoller.start() } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось запустить анализ.')) } finally { previewBusy.value = false } }
async function recoverPreview(publicId: string) { try { preview.value = (await adminPriceIndicesApi.getPreview(publicId)).data; if (preview.value.status === 'ready') await loadPreviewResult(publicId); else if (['pending', 'running'].includes(preview.value.status)) previewPoller.start() } catch { await updateQuery({ preview: undefined }) } }
async function loadPreviewResult(publicId: string) { previewResult.value = (await adminPriceIndicesApi.getPreviewResult(publicId)).data }
async function retryPreview() { if (!preview.value || previewBusy.value) return; previewBusy.value = true; try { const response = await adminPriceIndicesApi.retryPreview(preview.value.public_id); preview.value = response.data; previewResult.value = null; previewCached.value = false; await updateQuery({ preview: response.data.public_id }); previewPoller.start(); notify('Повторный анализ поставлен в очередь.') } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось повторить анализ.')) } finally { previewBusy.value = false } }
function openImportConfirm() { importDialog.value = true }
async function startImport() { if (!preview.value || importBusy.value) return; importBusy.value = true; try { const response = await adminPriceIndicesApi.startImport(preview.value.source_file.public_id); currentImport.value = response.data; importDialog.value = false; await updateQuery({ import: response.data.public_id }); importPoller.start(); notify('Импорт поставлен в очередь.') } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось запустить импорт.')) } finally { importBusy.value = false } }
async function recoverImport(publicId: string) { try { currentImport.value = (await adminPriceIndicesApi.getImport(publicId)).data; if (['pending', 'importing', 'validating'].includes(currentImport.value.status)) importPoller.start() } catch { await updateQuery({ import: undefined }) } }
async function retryImport() { if (!currentImport.value || importBusy.value) return; importBusy.value = true; try { const response = await adminPriceIndicesApi.retryImport(currentImport.value.public_id); currentImport.value = response.data; await updateQuery({ import: response.data.public_id }); importPoller.start(); notify('Повторный импорт поставлен в очередь.') } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось повторить импорт.')) } finally { importBusy.value = false } }
async function publishImport() { if (!currentImport.value || importBusy.value) return; importBusy.value = true; try { currentImport.value = (await adminPriceIndicesApi.publishImport(currentImport.value.public_id)).data; publishDialog.value = false; notify('Статистические данные опубликованы.'); await Promise.all([loadActiveImport(), loadSourceFiles()]) } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось опубликовать импорт.')); if (isPublicationConflict(error)) await recoverImport(currentImport.value.public_id) } finally { importBusy.value = false } }
async function openIssues() { issuesDialog.value = true; issuesPage.value = 1; await loadIssues() }
async function loadIssues() { if (!currentImport.value) return; issuesLoading.value = true; try { const response = await adminPriceIndicesApi.getImportIssues(currentImport.value.public_id, issuesPage.value, issuesPerPage.value); issues.value = response.data; issuesTotal.value = response.meta.total } catch (error) { notifyError(getPriceIndicesErrorMessage(error, 'Не удалось загрузить проблемы импорта.')) } finally { issuesLoading.value = false } }
</script>
