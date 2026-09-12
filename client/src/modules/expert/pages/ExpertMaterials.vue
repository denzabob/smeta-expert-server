<template>
  <div class="expert-section-page">
    <div class="expert-section-page__header">
      <div><span>Материалы</span><h1>Материалы проекта</h1><p>Приватные документы, изображения и таблицы проекта.</p></div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="requestUpload">Добавить материалы</v-btn>
      <input ref="fileInput" hidden type="file" multiple accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.webp" @change="uploadFiles" />
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />
    <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-3">{{ errorMessage }}</v-alert>
    <v-alert v-if="failedUploads.length" type="error" variant="tonal" density="compact" class="mb-3">
      Не удалось загрузить {{ failedUploads.length }} {{ pluralizeFiles(failedUploads.length) }}. Повторите загрузку только для отмеченных файлов.
    </v-alert>

    <div v-if="uploadItems.length" class="expert-materials__uploads" aria-live="polite">
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
        <div class="expert-material-card__body"><strong>{{ item.name }}</strong><span>{{ item.format }} · {{ item.size }}</span><small :class="{ 'text-error': item.state === 'error' }">{{ item.state === 'error' ? uploadError(item) : uploadStateLabel(item.state) }}</small></div>
        <v-btn v-if="item.state === 'error'" color="primary" variant="tonal" size="small" prepend-icon="mdi-reload" @click="retryUpload(item.id)">Повторить</v-btn>
      </article>
    </div>

    <div class="expert-materials__controls">
      <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Поиск по материалам" variant="outlined" density="compact" hide-details clearable />
      <v-chip-group v-model="filter" mandatory selected-class="text-primary">
        <v-chip v-for="item in filters" :key="item.value" :value="item.value" filter variant="tonal" size="small">{{ item.label }}</v-chip>
      </v-chip-group>
    </div>

    <div v-if="filteredMaterials.length" class="expert-materials__grid">
      <article v-for="material in filteredMaterials" :key="material.id" class="expert-material-card">
        <button class="expert-material-card__open" type="button" :aria-label="`Открыть свойства: ${material.name}`" @click="openMaterial(material)">
          <div class="expert-material-card__visual" :class="`expert-material-card__visual--${materialAccent(material)}`">
            <template v-if="material.kind === 'image'">
              <v-progress-circular v-if="imagePreview(material.id)?.status === 'loading'" indeterminate color="primary" size="24" width="3" aria-label="Загружается миниатюра изображения" />
              <img v-else-if="imagePreview(material.id)?.status === 'ready'" :src="imagePreview(material.id)?.url" :alt="`Миниатюра: ${material.name}`" class="expert-material-card__thumbnail" @error="markImagePreviewError(material.id)" />
              <v-icon v-else :icon="material.icon" size="28" />
            </template>
            <v-icon v-else :icon="material.icon" size="28" />
          </div>
          <div class="expert-material-card__body"><strong>{{ material.name }}</strong><span>{{ material.format }} · {{ material.size }}</span><small>{{ material.category }}</small></div>
        </button>
        <v-chip v-if="isDownloading(material.id)" size="x-small" color="primary" variant="tonal"><v-progress-circular indeterminate size="12" width="2" class="mr-1" />{{ downloadLabel(material.id) }}</v-chip>
        <v-chip v-else size="x-small" variant="tonal" :color="statusColor(material.status)">{{ material.status }}</v-chip>
        <v-menu>
          <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" icon="mdi-dots-vertical" size="small" variant="text" :aria-label="`Действия материала: ${material.name}`" /></template>
          <v-list density="compact">
            <v-list-item :title="downloadLabel(material.id)" :disabled="isDownloading(material.id)" @click="download(material)"><template #prepend><v-progress-circular v-if="isDownloading(material.id)" indeterminate size="18" width="2" /><v-icon v-else icon="mdi-download-outline" /></template></v-list-item>
            <v-list-item v-if="projectMode === 'real'" title="Удалить" prepend-icon="mdi-delete-outline" base-color="error" @click="requestRemove(material)" />
          </v-list>
        </v-menu>
      </article>
    </div>
    <div v-else-if="!loading && !uploadItems.length" class="expert-empty">
      <v-icon icon="mdi-folder-open-outline" size="44" />
      <h2>Материалы пока не добавлены</h2>
      <p>Допустимы PDF, DOCX, XLSX, JPG, JPEG, PNG и WEBP размером до 50 МБ.</p>
      <v-btn color="primary" variant="tonal" @click="requestUpload">Добавить материалы</v-btn>
    </div>

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
import type { ExpertMaterialKind, ExpertMaterialStatus, ExpertProject, ExpertProjectMaterial, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const search = ref('')
const filter = ref<'all' | ExpertMaterialKind>('all')
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
const filteredMaterials = computed(() => props.project.materials.filter((item) =>
  (filter.value === 'all' || item.kind === filter.value) &&
  item.name.toLowerCase().includes(search.value.trim().toLowerCase()),
))
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
    props.project.counts && (props.project.counts.materials = props.project.materials.length)
    void transfers.loadImagePreview(material)
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

function markImagePreviewError(id: string) {
  transfers.markImagePreviewError(id)
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
  return status === 'Ошибка' ? 'error' : status === 'Обрабатывается' ? 'warning' : status === 'Загружен' ? 'primary' : 'success'
}

watch(() => props.project.id, loadMaterials, { immediate: true })
watch(() => props.project.materials.map((material) => material.id), () => {
  transfers.syncImagePreviews(props.project.materials)
  props.project.materials.filter((material) => material.kind === 'image').forEach((material) => void transfers.loadImagePreview(material))
}, { immediate: true })
onBeforeUnmount(() => transfers.dispose())
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
.expert-materials__controls { display: grid; grid-template-columns: minmax(240px, 380px) minmax(0, 1fr); align-items: center; gap: 18px; margin-bottom: 16px; }
.expert-material-card { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center; gap: 13px; min-height: 72px; padding: 11px 14px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); }
.expert-material-card--transfer { background: rgb(var(--v-theme-surface-container-low)); }
.expert-material-card__open { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; min-width: 0; gap: 13px; padding: 0; border: 0; color: inherit; background: transparent; cursor: pointer; font: inherit; text-align: left; }
.expert-material-card:not(.expert-material-card--transfer):hover { border-color: rgba(var(--v-theme-primary), .45); background: rgba(var(--v-theme-primary), .035); }
.expert-material-card__visual { display: grid; place-items: center; width: 46px; height: 46px; overflow: hidden; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface-variant)); background: rgba(var(--v-theme-on-surface-variant), .1); }
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
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; text-align: center; color: rgba(var(--v-theme-on-surface-variant), .75); }
.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }
.expert-empty p { max-width: 440px; margin: 0 0 18px; font-size: .8rem; }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px 76px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } .expert-materials__controls { grid-template-columns: 1fr; gap: 8px; } .expert-materials__controls :deep(.v-slide-group) { max-width: calc(100vw - 60px); } .expert-material-card { grid-template-columns: minmax(0, 1fr) auto; } .expert-material-card > .v-chip { display: none; } .expert-material-card > .v-menu { grid-column: 2; grid-row: 1; } .expert-material-card--transfer > .v-btn { grid-column: 1 / -1; justify-self: start; } }
</style>
