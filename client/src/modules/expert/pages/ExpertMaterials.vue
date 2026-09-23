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
        <div class="expert-material-card__body"><strong :title="item.name">{{ item.name }}</strong><span>{{ item.format }} · {{ item.size }}</span></div>
        <div class="expert-material-card__status">
          <v-chip size="x-small" variant="tonal" :color="item.state === 'error' ? 'error' : item.state === 'completed' ? undefined : 'primary'" :title="item.state === 'error' ? uploadError(item) : undefined">{{ uploadStateLabel(item.state) }}</v-chip>
        </div>
        <v-btn v-if="item.state === 'error'" class="expert-material-card__transfer-action" color="primary" variant="tonal" size="small" icon="mdi-reload" :aria-label="`Повторить загрузку: ${item.name}`" @click="retryUpload(item.id)"><v-tooltip activator="parent" location="bottom">Повторить</v-tooltip></v-btn>
      </article>
    </div>

    <ExpertFileBrowser
      v-model:selected-ids="selectedIds"
      :items="props.project.materials"
      mode="library"
      storage-key="expert.materials"
      :thumbnails="thumbnailPreviews"
      :can-delete="projectMode === 'real'"
      @open="openMaterial"
      @download="download"
      @delete="requestRemove"
      @thumbnail-needed="transfers.loadImageThumbnail($event)"
      @thumbnail-error="markThumbnailError($event.id)"
    >
      <template #empty>
        <div v-if="!loading && !uploadItems.length && !props.project.materials.length" class="expert-empty">
          <v-icon icon="mdi-folder-open-outline" size="44" />
          <h2>Материалы пока не добавлены</h2>
          <p>Допустимы PDF, DOCX, XLSX, JPG, JPEG, PNG и WEBP размером до 50 МБ.</p>
          <v-btn color="primary" variant="tonal" @click="requestUpload">Добавить материалы</v-btn>
        </div>
        <p v-else class="expert-empty expert-empty--filtered">Материалы не найдены. Измените поиск или фильтр.</p>
      </template>
    </ExpertFileBrowser>

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
import ExpertFileBrowser from '../components/files/ExpertFileBrowser.vue'
import { useExpertMaterialTransfers, type ExpertMaterialUploadItem } from '../composables/useExpertMaterialTransfers'
import { expertApi, mapExpertApiError } from '../api'
import type { ExpertProject, ExpertProjectMaterial, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const selectedIds = ref<string[]>([])
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
const uploadItems = computed(() => transfers.uploads.value)
const uploadingCount = computed(() => transfers.uploadingCount.value)
const failedUploads = computed(() => uploadItems.value.filter((item) => item.state === 'error'))
const thumbnailPreviews = computed(() => transfers.thumbnailPreviews.value)

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

function markImagePreviewError(id: string) {
  transfers.markImagePreviewError(id)
}

function markThumbnailError(id: string) {
  transfers.markThumbnailError(id)
}

function isDownloading(id: string) {
  return transfers.isDownloading(id)
}

function downloadProgress(id: string) {
  return transfers.downloadProgress(id)
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

watch(() => props.project.id, () => {
  selectedIds.value = []
  selectedMaterial.value = null
  drawerOpen.value = false
  void loadMaterials()
}, { immediate: true })
watch(() => props.project.materials.map((material) => material.id), () => {
  transfers.syncImagePreviews(props.project.materials)
}, { immediate: true })
watch(selectedIds, (ids) => {
  const next = ids.length === 1 ? props.project.materials.find((item) => item.id === ids[0]) ?? null : null
  const previous = selectedMaterial.value
  if (previous && previous.id !== next?.id) transfers.releaseImagePreview(previous.id)
  if (drawerOpen.value && previous?.id !== next?.id) drawerOpen.value = false
  selectedMaterial.value = next
}, { flush: 'sync' })
watch(drawerOpen, (open) => {
  if (!open && selectedMaterial.value) transfers.releaseImagePreview(selectedMaterial.value.id)
})
onBeforeUnmount(() => transfers.dispose())
</script>

<style scoped>
.expert-section-page { padding: 26px; }
.expert-section-page__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.expert-section-page__header > div > span { color: rgb(var(--v-theme-primary)); font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-section-page h1 { margin: 4px 0; font-size: 1.55rem; }
.expert-section-page__header p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .8rem; }
.expert-materials__uploads { display: grid; gap: 9px; margin-bottom: 16px; }
.expert-materials__upload-summary { color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .75rem; font-weight: 700; }
.expert-material-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 10px; min-height: 64px; padding: 8px 12px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface-container-low)); }
.expert-material-card__visual { display: grid; place-items: center; width: 44px; height: 44px; overflow: hidden; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface-variant)); background: rgba(var(--v-theme-on-surface-variant), .1); }
.expert-material-card__visual--pdf { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .1); }
.expert-material-card__visual--word { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .1); }
.expert-material-card__visual--spreadsheet { color: rgb(var(--v-theme-success)); background: rgba(var(--v-theme-success), .1); }
.expert-material-card__visual--archive { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .12); }
.expert-material-card__visual--image { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .09); }
.expert-material-card__progress-value { color: rgb(var(--v-theme-on-surface)); font-size: .62rem; font-weight: 800; }
.expert-material-card__body { min-width: 0; }
.expert-material-card__body strong, .expert-material-card__body span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-material-card__body strong { font-size: .84rem; }
.expert-material-card__body span { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .71rem; }
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; color: rgba(var(--v-theme-on-surface-variant), .75); text-align: center; }
.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }
.expert-empty p { max-width: 440px; margin: 0 0 18px; font-size: .8rem; }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px 76px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } }
</style>
