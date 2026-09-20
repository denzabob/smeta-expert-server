<template>
  <div class="expert-section-page">
    <div class="expert-section-page__header">
      <div><span>Материалы</span><h1>Библиотека проекта</h1><p>Приватные документы, изображения и таблицы проекта.</p></div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="requestUpload">Добавить материалы</v-btn>
      <input ref="fileInput" hidden type="file" multiple accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.webp" @change="uploadFiles" />
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />
    <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-3">{{ errorMessage }}</v-alert>
    <v-alert v-if="failedUploads.length" type="error" variant="tonal" density="compact" class="mb-3">
      Не удалось загрузить {{ failedUploads.length }} {{ pluralizeFiles(failedUploads.length) }}. Повторите загрузку только для отмеченных файлов.
    </v-alert>

    <div v-if="uploadItems.length" class="expert-materials__uploads" :class="{ 'expert-materials__uploads--tiles': viewMode === 'grid' }" aria-live="polite">
      <div v-if="uploadingCount" class="expert-materials__upload-summary">Загрузка: {{ uploadingCount }} {{ pluralizeFiles(uploadingCount) }}</div>
      <article v-for="item in uploadItems" :key="item.id" class="expert-material-card expert-material-card--transfer">
        <div class="expert-material-card__visual" :class="`expert-material-card__visual--${item.accent}`">
          <v-progress-circular
            :model-value="item.progress"
            :indeterminate="item.state === 'queued' || item.state === 'processing'"
            :aria-label="`${uploadStateLabel(item.state)}: ${item.name}`"
            :aria-valuenow="item.progress"
            :size="44"
            :width="4"
            color="primary"
          ><span class="expert-material-card__progress-value">{{ item.state === 'queued' ? '…' : `${item.progress}%` }}</span></v-progress-circular>
        </div>
        <div class="expert-material-card__body"><strong :title="item.name">{{ item.name }}</strong><span>{{ item.format }} · {{ item.size }}</span></div>
        <div class="expert-material-card__status">
          <v-chip size="x-small" variant="tonal" :color="item.state === 'error' ? 'error' : item.state === 'completed' ? undefined : 'primary'" :title="item.state === 'error' ? uploadError(item) : undefined">{{ uploadStateLabel(item.state) }}</v-chip>
        </div>
        <v-btn v-if="item.state === 'error'" class="expert-material-card__transfer-action" color="primary" variant="tonal" size="small" icon="mdi-reload" :aria-label="`Повторить загрузку: ${item.name}`" @click="retryUpload(item.id)"><v-tooltip activator="parent" location="bottom">Повторить</v-tooltip></v-btn>
      </article>
    </div>

    <div class="expert-materials__controls">
      <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Поиск по материалам" variant="outlined" density="compact" hide-details clearable />
      <v-chip-group v-model="filter" mandatory selected-class="text-primary">
        <v-chip v-for="item in filters" :key="item.value" :value="item.value" filter variant="tonal" size="small">{{ item.label }}</v-chip>
      </v-chip-group>
      <div class="expert-materials__view-controls">
        <div class="expert-materials__view-picker">
          <span class="expert-materials__control-label">Вид</span>
          <div class="expert-materials__view-toggle">
            <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary" variant="text" aria-label="Вид материалов">
              <v-btn value="list" icon="mdi-format-list-bulleted" size="small" aria-label="Компактный список" title="Компактный список"><v-tooltip activator="parent" location="bottom">Компактный список</v-tooltip></v-btn>
              <v-btn value="grid" icon="mdi-view-grid-outline" size="small" aria-label="Плитка" title="Плитка"><v-tooltip activator="parent" location="bottom">Плитка</v-tooltip></v-btn>
            </v-btn-toggle>
          </div>
        </div>
        <div class="expert-materials__page-size">
          <span class="expert-materials__control-label">На странице</span>
          <v-select v-model="pageSize" :items="pageSizeOptions" density="compact" variant="outlined" hide-details aria-label="Количество материалов на странице" />
        </div>
      </div>
    </div>

    <div v-if="pagedMaterials.length" class="expert-materials__grid" :class="{ 'expert-materials__grid--tiles': viewMode === 'grid' }">
      <article v-for="material in pagedMaterials" :key="material.id" class="expert-material-card">
        <button class="expert-material-card__open" type="button" :aria-label="`Открыть свойства: ${material.name}`" @click="openMaterial(material)">
          <div :ref="(element) => setThumbnailTarget(material.id, element)" class="expert-material-card__visual" :class="`expert-material-card__visual--${materialAccent(material)}`">
            <template v-if="material.kind === 'image'">
              <v-progress-circular v-if="thumbnailPreview(material.id)?.status === 'loading'" indeterminate color="primary" size="24" width="3" aria-label="Загружается миниатюра изображения" />
              <img v-else-if="thumbnailPreview(material.id)?.status === 'ready'" :src="thumbnailPreview(material.id)?.url" :alt="`Миниатюра: ${material.name}`" class="expert-material-card__thumbnail" @error="markThumbnailError(material.id)" />
              <v-icon v-else :icon="material.icon" size="28" />
            </template>
            <v-icon v-else :icon="material.icon" size="28" />
          </div>
          <div class="expert-material-card__body"><strong :title="material.name">{{ material.name }}</strong><span>{{ material.format }} · {{ material.size }}</span></div>
        </button>
        <div class="expert-material-card__status">
          <v-chip v-if="isDownloading(material.id)" size="x-small" color="primary" variant="tonal"><v-progress-circular indeterminate size="12" width="2" class="mr-1" />{{ downloadLabel(material.id) }}</v-chip>
          <v-chip v-else size="x-small" variant="tonal" :color="statusColor(material.status)">{{ material.status }}</v-chip>
        </div>
        <div class="expert-material-card__actions" @click.stop>
          <v-menu>
            <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" icon="mdi-dots-vertical" size="small" variant="text" :aria-label="`Действия материала: ${material.name}`" @click.stop /></template>
            <v-list density="compact" @click.stop>
              <v-list-item :title="downloadLabel(material.id)" :disabled="isDownloading(material.id)" @click.stop="download(material)"><template #prepend><v-progress-circular v-if="isDownloading(material.id)" indeterminate size="18" width="2" /><v-icon v-else icon="mdi-download-outline" /></template></v-list-item>
              <v-list-item v-if="projectMode === 'real'" title="Удалить" prepend-icon="mdi-delete-outline" base-color="error" @click.stop="requestRemove(material)" />
            </v-list>
          </v-menu>
        </div>
      </article>
    </div>
    <div v-else-if="!loading && !uploadItems.length && !props.project.materials.length" class="expert-empty">
      <v-icon icon="mdi-folder-open-outline" size="44" />
      <h2>Материалы пока не добавлены</h2>
      <p>Допустимы PDF, DOCX, XLSX, JPG, JPEG, PNG и WEBP размером до 50 МБ.</p>
      <v-btn color="primary" variant="tonal" @click="requestUpload">Добавить материалы</v-btn>
    </div>
    <div v-else-if="!loading && !uploadItems.length" class="expert-empty expert-empty--filtered">
      <v-icon icon="mdi-filter-off-outline" size="38" />
      <h2>Материалы не найдены</h2>
      <p>Измените поиск или фильтр, чтобы увидеть другие материалы.</p>
    </div>
    <v-pagination v-if="pageCount > 1 && pagedMaterials.length" v-model="page" :length="pageCount" density="comfortable" class="expert-materials__pagination" aria-label="Страницы материалов" />

    <ExpertMaterialDrawer
      v-if="drawerOpen"
      v-model="drawerOpen"
      :material="selectedMaterial"
      :project-mode="projectMode"
      :image-preview="selectedMaterial ? imagePreview(selectedMaterial.id) : undefined"
      :downloading="selectedMaterial ? isDownloading(selectedMaterial.id) : false"
      :download-progress="selectedMaterial ? downloadProgress(selectedMaterial.id) : undefined"
      @action="handleDrawerAction"
      @image-error="selectedMaterial && markImagePreviewError(selectedMaterial.id)"
    />

    <v-dialog v-model="deleteDialogOpen" max-width="480" :persistent="Boolean(deletingMaterialId)">
      <v-card>
        <v-card-title>Удалить материал?</v-card-title>
        <v-card-text>
          <p>«{{ materialPendingDeletion?.name }}» будет удалён из проекта. Это действие нельзя отменить.</p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn :disabled="Boolean(deletingMaterialId)" @click="deleteDialogOpen = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="Boolean(deletingMaterialId)" :disabled="!materialPendingDeletion" @click="confirmRemove">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbarOpen" :color="snackbarColor">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ExpertMaterialDrawer from '../components/materials/ExpertMaterialDrawer.vue'
import { useExpertMaterialTransfers, type ExpertMaterialUploadItem } from '../composables/useExpertMaterialTransfers'
import { expertApi, mapExpertApiError } from '../api'
import { describeProjectMaterial } from '../materialPresentation'
import { filterAndPaginateMaterials } from '../materialPagination'
import type { ExpertMaterialKind, ExpertMaterialStatus, ExpertProject, ExpertProjectMaterial, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const search = ref('')
const filter = ref<'all' | ExpertMaterialKind>('all')
const pageSizeOptions = [25, 50, 100]
const viewMode = ref<'list' | 'grid'>(readViewMode())
const pageSize = ref(readPageSize())
const page = ref(1)
const drawerOpen = ref(false)
const selectedMaterial = ref<ExpertProjectMaterial | null>(null)
const loading = ref(false)
const errorMessage = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const snackbarOpen = ref(false)
const snackbarText = ref('')
const snackbarColor = ref<string | undefined>()
const deleteDialogOpen = ref(false)
const materialPendingDeletion = ref<ExpertProjectMaterial | null>(null)
const deletingMaterialId = ref<string | null>(null)
const transfers = useExpertMaterialTransfers()
let loadSequence = 0
const filters: { label: string; value: 'all' | ExpertMaterialKind }[] = [
  { label: 'Все', value: 'all' },
  { label: 'Документы', value: 'document' },
  { label: 'Изображения', value: 'image' },
  { label: 'Таблицы', value: 'spreadsheet' },
  { label: 'Прочее', value: 'other' },
]
const materialPage = computed(() => filterAndPaginateMaterials(props.project.materials, search.value, filter.value, page.value, pageSize.value))
const filteredMaterials = computed(() => materialPage.value.filtered)
const pageCount = computed(() => materialPage.value.pageCount)
const pagedMaterials = computed(() => materialPage.value.items)
const uploadItems = computed(() => transfers.uploads.value)
const uploadingCount = computed(() => transfers.uploadingCount.value)
const failedUploads = computed(() => uploadItems.value.filter((item) => item.state === 'error'))

async function loadMaterials() {
  const sequence = ++loadSequence
  if (props.projectMode === 'demo') { loading.value = false; errorMessage.value = ''; return }
  const targetProject = props.project
  loading.value = true
  errorMessage.value = ''
  try {
    const loaded = await expertApi.listMaterials(targetProject.id)
    if (sequence !== loadSequence) return
    targetProject.materials = loaded
    targetProject.counts && (targetProject.counts.materials = loaded.length)
  } catch (error) {
    if (sequence === loadSequence) errorMessage.value = mapExpertApiError(error).message
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

function requestUpload() {
  if (props.projectMode === 'demo') {
    showSnackbar('Загрузка недоступна в демонстрационном проекте.')
    return
  }
  fileInput.value?.click()
}

function uploadFiles(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (!files.length) return

  transfers.queueUploads(props.project.id, files, (material) => {
    if (props.project.materials.some((item) => item.id === material.id)) return
    props.project.materials.unshift(material)
    page.value = 1
    props.project.counts && (props.project.counts.materials = props.project.materials.length)
    void transfers.loadImageThumbnail(material)
  })
  input.value = ''
}

function retryUpload(id: string) {
  transfers.retryUpload(props.project.id, id)
}

async function download(material: ExpertProjectMaterial) {
  if (props.projectMode === 'demo') {
    showSnackbar('Скачивание недоступно в демонстрационном проекте.')
    return
  }
  const result = await transfers.downloadMaterial(material)
  if (!result.ok && !result.ignored) showSnackbar(mapExpertApiError(result.error).message, 'error')
  if (result.ok) showSnackbar('Скачивание началось.', 'success')
}

function requestRemove(material: ExpertProjectMaterial) {
  materialPendingDeletion.value = material
  deleteDialogOpen.value = true
}

async function confirmRemove() {
  const material = materialPendingDeletion.value
  if (!material || deletingMaterialId.value) return

  deletingMaterialId.value = material.id
  try {
    await expertApi.deleteMaterial(material.id)
    props.project.materials = props.project.materials.filter((item) => item.id !== material.id)
    props.project.counts && (props.project.counts.materials = props.project.materials.length)
    transfers.releaseImagePreview(material.id)
    if (selectedMaterial.value?.id === material.id) {
      drawerOpen.value = false
      selectedMaterial.value = null
    }
    deleteDialogOpen.value = false
    materialPendingDeletion.value = null
    showSnackbar('Материал удалён.', 'success')
  } catch (error) {
    const mapped = mapExpertApiError(error)
    showSnackbar(mapped.status === 409 ? 'Материал используется в результатах исследования и пока не может быть удалён.' : mapped.message, 'error')
  } finally {
    deletingMaterialId.value = null
  }
}

function openMaterial(material: ExpertProjectMaterial) {
  if (selectedMaterial.value && selectedMaterial.value.id !== material.id) transfers.releaseImagePreview(selectedMaterial.value.id)
  selectedMaterial.value = material
  drawerOpen.value = true
  void transfers.loadImagePreview(material)
}

function handleDrawerAction(action: string) {
  if (!selectedMaterial.value) return
  if (action === 'download') void download(selectedMaterial.value)
  else if (action === 'delete') requestRemove(selectedMaterial.value)
  else showSnackbar(action + ': функция доступна только в демонстрационном режиме.')
}

function imagePreview(id: string) {
  return transfers.imagePreviews.value[id]
}

function thumbnailPreview(id: string) {
  return transfers.thumbnailPreviews.value[id]
}

function markImagePreviewError(id: string) {
  transfers.markImagePreviewError(id)
}

function markThumbnailError(id: string) {
  transfers.markThumbnailError(id)
}

let thumbnailObserver: IntersectionObserver | null = null
if (typeof IntersectionObserver !== 'undefined') {
  thumbnailObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return
      const id = (entry.target as HTMLElement).dataset.materialId
      const material = id ? pagedMaterials.value.find((item) => item.id === id) : undefined
      if (material) void transfers.loadImageThumbnail(material)
      thumbnailObserver?.unobserve(entry.target)
    })
  }, { rootMargin: '160px' })
}

function setThumbnailTarget(id: string, element: unknown) {
  if (!(element instanceof HTMLElement)) return
  element.dataset.materialId = id
  thumbnailObserver?.observe(element)
}

function materialAccent(material: ExpertProjectMaterial) {
  return describeProjectMaterial(material).accent
}

function isDownloading(id: string) {
  return transfers.isDownloading(id)
}

function downloadProgress(id: string) {
  return transfers.downloadProgress(id)
}

function downloadLabel(id: string) {
  const progress = downloadProgress(id)
  return isDownloading(id) ? progress === null || progress === undefined ? 'Скачивание…' : `Скачивание ${progress}%` : 'Скачать'
}

function uploadStateLabel(state: ExpertMaterialUploadItem['state']) {
  return state === 'queued' ? 'В очереди' : state === 'uploading' ? 'Загружается' : state === 'processing' ? 'Обрабатывается' : state === 'completed' ? 'Загружен' : 'Не удалось загрузить'
}

function uploadError(item: ExpertMaterialUploadItem) {
  return item.error ? mapExpertApiError(item.error).message : 'Не удалось загрузить'
}

function pluralizeFiles(value: number) {
  return value % 10 === 1 && value % 100 !== 11 ? 'файл' : value % 10 >= 2 && value % 10 <= 4 && (value % 100 < 10 || value % 100 >= 20) ? 'файла' : 'файлов'
}

function showSnackbar(message: string, color?: string) {
  snackbarText.value = message
  snackbarColor.value = color
  snackbarOpen.value = true
}

function statusColor(status: ExpertMaterialStatus) {
  return status === 'Ошибка' ? 'error' : status === 'Обрабатывается' ? 'warning' : status === 'Загружен' ? undefined : 'success'
}

watch(() => props.project.id, loadMaterials, { immediate: true })
watch(() => props.project.materials.map((material) => material.id), () => {
  transfers.syncImagePreviews(props.project.materials)
  page.value = Math.min(page.value, pageCount.value)
}, { immediate: true })
watch([search, filter, pageSize], () => { page.value = 1 })
watch(drawerOpen, (open) => {
  if (!open && selectedMaterial.value) transfers.releaseImagePreview(selectedMaterial.value.id)
})
watch(viewMode, (value) => writeStorage('expert.materials.view', value))
watch(pageSize, (value) => writeStorage('expert.materials.pageSize', String(value)))
onBeforeUnmount(() => { thumbnailObserver?.disconnect(); transfers.dispose() })

function readStorage(key: string, fallback: string): string {
  try { return window.localStorage.getItem(key) ?? fallback } catch { return fallback }
}

function readViewMode(): 'list' | 'grid' {
  return readStorage('expert.materials.view', 'list') === 'grid' ? 'grid' : 'list'
}

function readPageSize(): number {
  const value = Number(readStorage('expert.materials.pageSize', '25'))
  return pageSizeOptions.includes(value) ? value : 25
}

function writeStorage(key: string, value: string) {
  try { window.localStorage.setItem(key, value) } catch { /* storage is optional */ }
}
</script>

<style scoped>
.expert-section-page { padding: 26px; }
.expert-section-page__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.expert-section-page__header > div > span { color: rgb(var(--v-theme-primary)); font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-section-page h1 { margin: 4px 0; font-size: 1.55rem; }
.expert-section-page__header p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .8rem; }
.expert-materials__uploads, .expert-materials__grid { display: grid; gap: 9px; }
.expert-materials__uploads { margin-bottom: 16px; }
.expert-materials__upload-summary { color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .75rem; font-weight: 700; }
.expert-materials__controls { display: grid; grid-template-columns: minmax(260px, 380px) minmax(0, 1fr) auto; align-items: center; gap: 12px 18px; margin-bottom: 16px; }
.expert-materials__view-controls, .expert-materials__view-picker, .expert-materials__page-size { display: flex; align-items: center; }
.expert-materials__view-controls { justify-content: flex-end; gap: 18px; }
.expert-materials__view-picker, .expert-materials__page-size { gap: 8px; }
.expert-materials__control-label { color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .72rem; font-weight: 700; white-space: nowrap; }
.expert-materials__view-toggle { display: inline-flex; overflow: hidden; border: 1px solid rgba(var(--v-theme-outline-variant), .9); border-radius: var(--md-sys-shape-corner-medium); }
.expert-materials__view-toggle :deep(.v-btn-toggle) { height: 34px; border: 0; border-radius: 0; }
.expert-materials__view-toggle :deep(.v-btn) { width: 40px; min-width: 40px; height: 34px; padding: 0; border: 0 !important; border-radius: 0 !important; color: rgb(var(--v-theme-on-surface-variant)); background: transparent; }
.expert-materials__view-toggle :deep(.v-btn + .v-btn) { border-left: 1px solid rgba(var(--v-theme-outline-variant), .9) !important; }
.expert-materials__view-toggle :deep(.v-btn--active) { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .12); }
.expert-materials__page-size :deep(.v-select) { width: 92px; }
.expert-materials__page-size :deep(.v-field) { min-height: 36px; }
.expert-material-card { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 10px; min-height: 64px; padding: 8px 12px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); }
.expert-material-card--transfer { grid-template-columns: auto minmax(0, 1fr) auto auto; background: rgb(var(--v-theme-surface-container-low)); }
.expert-material-card__open { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; min-width: 0; gap: 13px; padding: 0; border: 0; color: inherit; background: transparent; cursor: pointer; font: inherit; text-align: left; }
.expert-material-card:not(.expert-material-card--transfer) { transition: border-color 140ms ease, background-color 140ms ease, box-shadow 140ms ease; }
.expert-material-card:not(.expert-material-card--transfer):hover { border-color: rgba(var(--v-theme-primary), .45); background: rgba(var(--v-theme-primary), .035); box-shadow: 0 2px 10px rgba(var(--v-theme-on-surface), .06); }
.expert-material-card__open:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 3px; border-radius: var(--md-sys-shape-corner-small); }
.expert-material-card__visual { display: grid; place-items: center; width: 44px; height: 44px; overflow: hidden; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface-variant)); background: rgba(var(--v-theme-on-surface-variant), .1); }
.expert-material-card__visual--pdf { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .1); }
.expert-material-card__visual--word { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .1); }
.expert-material-card__visual--spreadsheet { color: rgb(var(--v-theme-success)); background: rgba(var(--v-theme-success), .1); }
.expert-material-card__visual--archive { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .12); }
.expert-material-card__visual--image { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .09); }
.expert-material-card__thumbnail { width: 100%; height: 100%; object-fit: cover; }
.expert-material-card__progress-value { color: rgb(var(--v-theme-on-surface)); font-size: .62rem; font-weight: 800; }
.expert-material-card__body { min-width: 0; }
.expert-material-card__body strong, .expert-material-card__body span, .expert-material-card__body small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-material-card__body strong { font-size: .84rem; }
.expert-material-card__body span { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .71rem; }
.expert-material-card__body small { margin-top: 2px; color: rgba(var(--v-theme-on-surface-variant), .58); font-size: .66rem; }
.expert-materials__grid--tiles, .expert-materials__uploads--tiles { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.expert-materials__uploads--tiles .expert-materials__upload-summary { grid-column: 1 / -1; }
.expert-materials__grid--tiles .expert-material-card, .expert-materials__uploads--tiles .expert-material-card { position: relative; display: block; min-height: 0; padding: 0; overflow: hidden; }
.expert-materials__grid--tiles .expert-material-card__open { display: block; width: 100%; }
.expert-materials__grid--tiles .expert-material-card__visual, .expert-materials__uploads--tiles .expert-material-card__visual { width: 100%; height: auto; aspect-ratio: 4 / 3; border-radius: 0; }
.expert-materials__grid--tiles .expert-material-card__visual > .v-icon { font-size: 58px !important; }
.expert-materials__grid--tiles .expert-material-card__body, .expert-materials__uploads--tiles .expert-material-card__body { min-height: 70px; padding: 12px 14px 13px; }
.expert-materials__grid--tiles .expert-material-card__body strong, .expert-materials__uploads--tiles .expert-material-card__body strong { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; white-space: normal; line-height: 1.3; }
.expert-materials__grid--tiles .expert-material-card__status, .expert-materials__uploads--tiles .expert-material-card__status { position: absolute; z-index: 2; top: 10px; right: 50px; max-width: calc(100% - 64px); }
.expert-materials__grid--tiles .expert-material-card__status :deep(.v-chip), .expert-materials__uploads--tiles .expert-material-card__status :deep(.v-chip) { max-width: 100%; background: rgba(var(--v-theme-surface), .9); backdrop-filter: blur(5px); }
.expert-materials__grid--tiles .expert-material-card__status :deep(.v-chip__content), .expert-materials__uploads--tiles .expert-material-card__status :deep(.v-chip__content) { overflow: hidden; text-overflow: ellipsis; }
.expert-materials__grid--tiles .expert-material-card__actions { position: absolute; z-index: 3; top: 5px; right: 5px; border-radius: 999px; background: rgba(var(--v-theme-surface), .86); backdrop-filter: blur(5px); }
.expert-materials__uploads--tiles .expert-material-card__transfer-action { position: absolute; z-index: 3; top: 5px; right: 5px; }
.expert-materials__pagination { margin-top: 18px; }
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; text-align: center; color: rgba(var(--v-theme-on-surface-variant), .75); }
.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }
.expert-empty p { max-width: 440px; margin: 0 0 18px; font-size: .8rem; }
@media (max-width: 1100px) { .expert-materials__controls { grid-template-columns: minmax(240px, 380px) minmax(0, 1fr); } .expert-materials__view-controls { grid-column: 1 / -1; } }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px 76px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } .expert-materials__controls { grid-template-columns: 1fr; gap: 8px; } .expert-materials__controls :deep(.v-slide-group) { max-width: calc(100vw - 26px); } .expert-materials__view-controls { grid-column: 1; justify-content: space-between; gap: 10px; } .expert-materials__control-label { display: none; } .expert-materials__grid--tiles, .expert-materials__uploads--tiles { grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr)); } .expert-material-card { grid-template-columns: minmax(0, 1fr) auto auto; } .expert-material-card--transfer { grid-template-columns: auto minmax(0, 1fr) auto auto; } }
@media (prefers-reduced-motion: reduce) { .expert-material-card:not(.expert-material-card--transfer) { transition: none; } }
</style>
