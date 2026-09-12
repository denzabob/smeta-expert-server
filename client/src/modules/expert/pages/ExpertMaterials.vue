<template>
  <div class="expert-section-page">
    <div class="expert-section-page__header">
      <div><span>Материалы</span><h1>Материалы проекта</h1><p>Приватные документы, изображения и таблицы проекта.</p></div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" :loading="uploading" @click="requestUpload">Добавить материалы</v-btn>
      <input ref="fileInput" hidden type="file" multiple accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.webp" @change="uploadFiles" />
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />
    <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-3">{{ errorMessage }}</v-alert>
    <div class="expert-materials__controls">
      <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Поиск по материалам" variant="outlined" density="compact" hide-details clearable />
      <v-chip-group v-model="filter" mandatory selected-class="text-primary">
        <v-chip v-for="item in filters" :key="item.value" :value="item.value" filter variant="tonal" size="small">{{ item.label }}</v-chip>
      </v-chip-group>
    </div>

    <div v-if="filteredMaterials.length" class="expert-materials__grid">
      <button v-for="material in filteredMaterials" :key="material.id" class="expert-material-card" type="button" @click="openMaterial(material)">
        <div class="expert-material-card__icon"><v-icon :icon="material.icon" size="28" /></div>
        <div class="expert-material-card__body"><strong>{{ material.name }}</strong><span>{{ material.format }} · {{ material.size }}</span><small>{{ material.category }}</small></div>
        <v-chip size="x-small" variant="tonal" :color="statusColor(material.status)">{{ material.status }}</v-chip>
        <v-menu>
          <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" icon="mdi-dots-vertical" size="small" variant="text" aria-label="Действия материала" @click.stop /></template>
          <v-list density="compact">
            <v-list-item title="Скачать" prepend-icon="mdi-download-outline" @click="download(material)" />
            <v-list-item v-if="projectMode === 'real'" title="Удалить" prepend-icon="mdi-delete-outline" base-color="error" @click="remove(material)" />
          </v-list>
        </v-menu>
      </button>
    </div>
    <div v-else-if="!loading" class="expert-empty">
      <v-icon icon="mdi-folder-open-outline" size="44" />
      <h2>Материалы пока не добавлены</h2>
      <p>Допустимы PDF, DOCX, XLSX, JPG, JPEG, PNG и WEBP размером до 50 МБ.</p>
      <v-btn color="primary" variant="tonal" @click="requestUpload">Добавить материалы</v-btn>
    </div>

    <ExpertMaterialDrawer v-model="drawerOpen" :material="selectedMaterial" :project-mode="projectMode" @action="handleDrawerAction" />
    <v-snackbar v-model="snackbarOpen" :color="snackbarColor">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import ExpertMaterialDrawer from '../components/materials/ExpertMaterialDrawer.vue'
import { expertApi, mapExpertApiError } from '../api'
import type { ExpertMaterialKind, ExpertMaterialStatus, ExpertProject, ExpertProjectMaterial, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const search = ref('')
const filter = ref<'all' | ExpertMaterialKind>('all')
const drawerOpen = ref(false)
const selectedMaterial = ref<ExpertProjectMaterial | null>(null)
const loading = ref(false)
const uploading = ref(false)
const errorMessage = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const snackbarOpen = ref(false)
const snackbarText = ref('')
const snackbarColor = ref<string | undefined>()
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

async function uploadFiles(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (!files.length) return
  uploading.value = true
  errorMessage.value = ''
  let uploaded = 0
  try {
    for (const file of files) {
      const material = await expertApi.uploadMaterial(props.project.id, file)
      props.project.materials.unshift(material)
      uploaded += 1
    }
    props.project.counts && (props.project.counts.materials = props.project.materials.length)
    showSnackbar('Загружено файлов: ' + uploaded, 'success')
  } catch (error) {
    errorMessage.value = mapExpertApiError(error).message
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function download(material: ExpertProjectMaterial) {
  if (props.projectMode === 'demo') {
    showSnackbar('Скачивание недоступно в демонстрационном проекте.')
    return
  }
  try {
    const blob = await expertApi.downloadMaterial(material.id)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = material.name
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = mapExpertApiError(error).message
  }
}

async function remove(material: ExpertProjectMaterial) {
  if (!window.confirm('Удалить материал «' + material.name + '»?')) return
  errorMessage.value = ''
  try {
    await expertApi.deleteMaterial(material.id)
    props.project.materials = props.project.materials.filter((item) => item.id !== material.id)
    props.project.counts && (props.project.counts.materials = props.project.materials.length)
    if (selectedMaterial.value?.id === material.id) drawerOpen.value = false
    showSnackbar('Материал удалён.', 'success')
  } catch (error) {
    errorMessage.value = mapExpertApiError(error).message
  }
}

function openMaterial(material: ExpertProjectMaterial) {
  selectedMaterial.value = material
  drawerOpen.value = true
}
function handleDrawerAction(action: string) {
  if (!selectedMaterial.value) return
  if (action === 'download') void download(selectedMaterial.value)
  else if (action === 'delete') void remove(selectedMaterial.value)
  else showSnackbar(action + ': функция доступна только в демонстрационном режиме.')
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
</script>

<style scoped>
.expert-section-page { padding: 26px; }
.expert-section-page__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.expert-section-page__header > div > span { color: rgb(var(--v-theme-primary)); font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-section-page h1 { margin: 4px 0; font-size: 1.55rem; }
.expert-section-page__header p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .8rem; }
.expert-materials__controls { display: grid; grid-template-columns: minmax(240px, 380px) minmax(0, 1fr); align-items: center; gap: 18px; margin-bottom: 16px; }
.expert-materials__grid { display: grid; gap: 9px; }
.expert-material-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 13px; width: 100%; min-height: 72px; padding: 11px 14px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; }
.expert-material-card:hover { border-color: rgba(var(--v-theme-primary), .45); background: rgba(var(--v-theme-primary), .035); }
.expert-material-card__icon { display: grid; place-items: center; width: 46px; height: 46px; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .09); }
.expert-material-card__body strong, .expert-material-card__body span, .expert-material-card__body small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-material-card__body strong { font-size: .84rem; }
.expert-material-card__body span { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .71rem; }
.expert-material-card__body small { margin-top: 2px; color: rgba(var(--v-theme-on-surface-variant), .58); font-size: .66rem; }
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; text-align: center; color: rgba(var(--v-theme-on-surface-variant), .75); }
.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }
.expert-empty p { max-width: 440px; margin: 0 0 18px; font-size: .8rem; }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px 76px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } .expert-materials__controls { grid-template-columns: 1fr; gap: 8px; } .expert-materials__controls :deep(.v-slide-group) { max-width: calc(100vw - 60px); } .expert-material-card { grid-template-columns: auto minmax(0, 1fr) auto; } .expert-material-card > .v-chip { display: none; } }
</style>
