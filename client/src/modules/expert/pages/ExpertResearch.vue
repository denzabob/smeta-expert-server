<template>
  <div class="expert-section-page expert-research">
    <div class="expert-section-page__header">
      <div><span>Структурированные данные</span><h1>Исследование</h1><p>Факты, измерения, дефекты, расчёты и выводы проекта.</p></div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openFinding()">Добавить запись</v-btn>
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />
    <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-3">{{ errorMessage }}</v-alert>
    <v-tabs v-model="tab" color="primary" class="expert-research__tabs">
      <v-tab v-for="item in tabs" :key="item.value" :value="item.value">{{ item.label }}<v-chip size="x-small" class="ml-2">{{ countFor(item.value) }}</v-chip></v-tab>
    </v-tabs>
    <v-divider class="mb-4" />

    <div v-if="visibleFindings.length" class="expert-research__list">
      <button v-for="finding in visibleFindings" :key="finding.id" type="button" class="expert-finding-card" @click="openFinding(finding)">
        <div class="expert-finding-card__number">{{ finding.number }}</div>
        <div class="expert-finding-card__body">
          <div><v-chip size="x-small" variant="tonal" color="primary">{{ finding.typeLabel }}</v-chip><v-chip size="x-small" variant="tonal" :color="findingStatusColor(finding.status)">{{ finding.status }}</v-chip></div>
          <h2>{{ finding.title }}</h2>
          <p>{{ finding.description || 'Описание не добавлено' }}</p>
          <small><v-icon icon="mdi-crosshairs-gps" size="14" />{{ researchObjectName(finding) }}<template v-if="finding.measurement"> · {{ finding.measurement }}</template></small>
        </div>
        <v-icon icon="mdi-chevron-right" />
      </button>
    </div>
    <div v-else-if="!loading" class="expert-empty">
      <v-icon icon="mdi-microscope" size="44" />
      <h2>Структурированные данные пока отсутствуют</h2>
      <p>{{ projectMode === 'demo' ? 'Данные доступны в демонстрационном сценарии.' : 'Добавьте первый результат исследования вручную.' }}</p>
    </div>

    <ExpertFindingDrawer v-if="projectMode === 'demo'" v-model="drawerOpen" :finding="selectedFinding" :project="project" @action="notify" />

    <v-dialog v-if="projectMode === 'real'" v-model="formOpen" max-width="680" scrollable>
      <v-card>
        <v-card-title>{{ editingFinding ? 'Редактировать результат' : 'Новый результат исследования' }}</v-card-title>
        <v-card-text>
          <div class="expert-finding-form">
            <v-select v-model="draft.type" :items="findingTypes" item-title="label" item-value="value" label="Тип" variant="outlined" />
            <v-text-field v-model="draft.title" label="Заголовок" variant="outlined" />
            <v-textarea v-model="draft.description" label="Описание" variant="outlined" rows="3" class="expert-finding-form__wide" />
            <v-text-field v-model="draft.value" label="Значение" variant="outlined" />
            <v-text-field v-model="draft.unit" label="Единица измерения" variant="outlined" />
            <v-select v-model="draft.status" :items="findingStatuses" item-title="label" item-value="value" label="Статус" variant="outlined" />
            <v-select v-model="draft.researchObjectId" :items="project.researchObjects" item-title="name" item-value="id" label="Объект исследования" variant="outlined" clearable />
            <v-select v-model="draft.materialIds" :items="project.materials" item-title="name" item-value="id" label="Материалы / доказательства" variant="outlined" multiple chips closable-chips class="expert-finding-form__wide" />
            <v-alert v-if="formError" type="error" variant="tonal" density="compact" class="expert-finding-form__wide">{{ formError }}</v-alert>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-btn v-if="editingFinding" color="error" variant="text" :loading="deleting" @click="deleteFinding">Удалить</v-btn>
          <v-spacer />
          <v-btn :disabled="saving || deleting" @click="formOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveFinding">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbarOpen">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import ExpertFindingDrawer from '../components/research/ExpertFindingDrawer.vue'
import { expertResearchTabs } from '../presentation'
import { expertApi, mapExpertApiError, type ExpertFindingInput } from '../api'
import type { ExpertFinding, ExpertFindingType, ExpertProject, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const tab = ref('all')
const drawerOpen = ref(false)
const selectedFinding = ref<ExpertFinding | null>(null)
const loading = ref(false)
const errorMessage = ref('')
const formOpen = ref(false)
const editingFinding = ref<ExpertFinding | null>(null)
const saving = ref(false)
const deleting = ref(false)
const formError = ref('')
const snackbarOpen = ref(false)
const snackbarText = ref('')
let loadSequence = 0
const tabs = expertResearchTabs
const findingTypes: { label: string; value: ExpertFindingType }[] = expertResearchTabs
  .filter((item): item is { label: string; value: ExpertFindingType } => item.value !== 'all')
const findingStatuses = [
  { label: 'Подтверждено экспертом', value: 'expert_confirmed' },
  { label: 'Предложено AI', value: 'ai_proposed' },
  { label: 'Отклонено экспертом', value: 'expert_rejected' },
] as const
const draft = reactive<{
  type: ExpertFindingType
  title: string
  description: string
  value: string
  unit: string
  status: ExpertFindingInput['status']
  researchObjectId: string | null
  materialIds: string[]
}>({
  type: 'fact',
  title: '',
  description: '',
  value: '',
  unit: '',
  status: 'expert_confirmed',
  researchObjectId: null,
  materialIds: [],
})

const visibleFindings = computed(() => tab.value === 'all'
  ? props.project.findings
  : props.project.findings.filter((finding) => finding.type === tab.value))

function resetDraft(finding?: ExpertFinding) {
  draft.type = finding?.type ?? 'fact'
  draft.title = finding?.title ?? ''
  draft.description = finding?.description ?? ''
  draft.value = finding?.value ?? ''
  draft.unit = finding?.unit ?? ''
  draft.status = finding?.status === 'Предложено AI'
    ? 'ai_proposed'
    : finding?.status === 'Отклонено экспертом'
      ? 'expert_rejected'
      : 'expert_confirmed'
  draft.researchObjectId = finding?.researchObjectId ?? null
  draft.materialIds = [...(finding?.materialIds ?? [])]
  formError.value = ''
}

function openFinding(finding?: ExpertFinding) {
  if (props.projectMode === 'demo') {
    selectedFinding.value = finding ?? null
    if (finding) drawerOpen.value = true
    else notify('Добавление записи в демо-режиме')
    return
  }
  editingFinding.value = finding ?? null
  resetDraft(finding)
  formOpen.value = true
}

function inputFromDraft(): ExpertFindingInput {
  return {
    type: draft.type,
    title: draft.title.trim(),
    description: draft.description.trim() || null,
    value: draft.value.trim() || null,
    unit: draft.unit.trim() || null,
    status: draft.status,
    research_object_public_id: draft.researchObjectId,
    material_public_ids: draft.materialIds,
  }
}

function renumberFindings() {
  props.project.findings.forEach((finding, index) => { finding.number = index + 1 })
  props.project.counts && (props.project.counts.findings = props.project.findings.length)
}

async function loadFindings() {
  const sequence = ++loadSequence
  if (props.projectMode === 'demo') { loading.value = false; errorMessage.value = ''; return }
  const targetProject = props.project
  loading.value = true
  errorMessage.value = ''
  try {
    const loaded = await expertApi.listFindings(targetProject.id)
    if (sequence !== loadSequence) return
    targetProject.findings = loaded
    renumberFindings()
  } catch (error) {
    if (sequence === loadSequence) errorMessage.value = mapExpertApiError(error).message
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function saveFinding() {
  if (!draft.title.trim()) {
    formError.value = 'Укажите заголовок результата.'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const saved = editingFinding.value
      ? await expertApi.updateFinding(editingFinding.value.id, inputFromDraft())
      : await expertApi.createFinding(props.project.id, inputFromDraft())
    const index = props.project.findings.findIndex((item) => item.id === saved.id)
    if (index >= 0) props.project.findings.splice(index, 1, saved)
    else props.project.findings.unshift(saved)
    renumberFindings()
    formOpen.value = false
  } catch (error) {
    const mapped = mapExpertApiError(error)
    formError.value = Object.values(mapped.validationErrors).flat()[0] ?? mapped.message
  } finally {
    saving.value = false
  }
}

async function deleteFinding() {
  if (!editingFinding.value || !window.confirm('Удалить результат «' + editingFinding.value.title + '»?')) return
  deleting.value = true
  formError.value = ''
  try {
    await expertApi.deleteFinding(editingFinding.value.id)
    props.project.findings = props.project.findings.filter((item) => item.id !== editingFinding.value?.id)
    renumberFindings()
    formOpen.value = false
  } catch (error) {
    formError.value = mapExpertApiError(error).message
  } finally {
    deleting.value = false
  }
}

function countFor(value: string) {
  return value === 'all'
    ? props.project.findings.length
    : props.project.findings.filter((finding) => finding.type === value as ExpertFindingType).length
}
function researchObjectName(finding: ExpertFinding) {
  return props.project.researchObjects.find((item) => item.id === finding.researchObjectId)?.name ?? finding.object
}
function findingStatusColor(status: ExpertFinding['status']) {
  if (status === 'Подтверждено экспертом') return 'success'
  if (status === 'Отклонено экспертом') return 'error'
  return 'warning'
}
function notify(action: string) {
  snackbarText.value = action + ': функция доступна только в демонстрационном режиме.'
  snackbarOpen.value = true
}

watch(() => props.project.id, loadFindings, { immediate: true })
</script>

<style scoped>
.expert-section-page { padding: 26px; }
.expert-section-page__header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 18px; }
.expert-section-page__header > div > span { color: rgb(var(--v-theme-primary)); font-size: .68rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-section-page h1 { margin: 4px 0; font-size: 1.55rem; }
.expert-section-page__header p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .8rem; }
.expert-research__tabs { max-width: 100%; overflow: hidden; }
.expert-research__list { display: grid; gap: 10px; }
.expert-finding-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 14px; width: 100%; padding: 16px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; }
.expert-finding-card:hover { border-color: rgba(var(--v-theme-primary), .45); }
.expert-finding-card__number { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); font-weight: 800; }
.expert-finding-card h2 { margin: 7px 0 4px; font-size: .9rem; }
.expert-finding-card p { margin: 0 0 7px; color: rgba(var(--v-theme-on-surface-variant), .78); font-size: .77rem; }
.expert-finding-card small { display: flex; align-items: center; gap: 4px; color: rgba(var(--v-theme-on-surface-variant), .68); font-size: .68rem; }
.expert-empty { display: grid; justify-items: center; padding: 64px 20px; text-align: center; color: rgba(var(--v-theme-on-surface-variant), .75); }
.expert-empty h2 { margin: 14px 0 4px; color: rgb(var(--v-theme-on-surface)); font-size: 1.05rem; }
.expert-empty p { max-width: 480px; margin: 0; font-size: .8rem; }
.expert-finding-form { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 14px; }
.expert-finding-form__wide { grid-column: 1 / -1; }
@media (max-width: 760px) { .expert-section-page { padding: 18px 13px; } .expert-section-page__header { align-items: stretch; flex-direction: column; } .expert-research__tabs { max-width: calc(100vw - 60px); } .expert-finding-card { grid-template-columns: auto minmax(0, 1fr); } .expert-finding-card > .v-icon { display: none; } .expert-finding-form { grid-template-columns: 1fr; } .expert-finding-form__wide { grid-column: auto; } }
</style>
