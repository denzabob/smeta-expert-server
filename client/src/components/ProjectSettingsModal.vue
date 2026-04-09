<template>
  <div>
    <!-- Unsaved changes confirmation dialog -->
    <v-dialog v-model="showUnsavedDialog" max-width="400" :z-index="1500">
      <v-card>
        <v-card-title class="text-subtitle-1 font-weight-bold pa-4">Несохранённые изменения</v-card-title>
        <v-card-text class="px-4 pb-2">
          В настройках проекта есть несохранённые изменения. Что сделать?
        </v-card-text>
        <v-card-actions class="pa-4 pt-2 gap-2">
          <v-btn variant="text" @click="showUnsavedDialog = false">Остаться</v-btn>
          <v-spacer />
          <v-btn variant="outlined" color="error" @click="discardAndClose">Отменить изменения</v-btn>
          <v-btn variant="flat" color="primary" :loading="isSaving" @click="saveAndClose">Сохранить и выйти</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Coefficient description dialog -->
    <v-dialog v-model="coefficientDescriptionDialog" max-width="600" :z-index="1400">
      <v-card>
        <v-card-title class="pa-4">Редактировать описание для {{ getCoefficientTypeLabel() }}</v-card-title>
        <v-card-text class="px-4 pb-2">
          <v-text-field
            v-model="coefficientDescriptionForm.title"
            label="Заголовок"
            variant="outlined"
            placeholder="Например: Причина использования коэффициента"
            counter="200"
            maxlength="200"
            class="mb-3"
          />
          <v-textarea
            v-model="coefficientDescriptionForm.text"
            label="Описание"
            variant="outlined"
            rows="6"
            placeholder="Описание коэффициента для отчёта"
            counter="2000"
            maxlength="2000"
            @paste="onPasteCoefficientDescription"
          />
        </v-card-text>
        <v-card-actions class="pa-4 pt-2">
          <v-spacer />
          <v-btn variant="text" @click="closeCoefficientDescriptionDialog">Отменить</v-btn>
          <v-btn color="primary" variant="flat" @click="saveCoefficientDescription">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Main settings modal -->
    <v-dialog
      :model-value="modelValue"
      :fullscreen="isMobile"
      :max-width="isMobile ? undefined : 1100"
      persistent
      :scrim="true"
      :z-index="1300"
      class="psm-dialog"
      @keydown.esc="handleEscKey"
    >
      <v-card class="psm-card d-flex flex-column" :rounded="isMobile ? '0' : 'lg'">
        <!-- Header -->
        <div class="psm-header d-flex align-center justify-space-between px-5 py-3 border-b flex-shrink-0">
          <span class="text-subtitle-1 font-weight-bold">Настройки проекта</span>
          <div class="d-flex align-center gap-2">
            <v-tooltip location="bottom">
              <template #activator="{ props }">
                <v-btn
                  icon
                  variant="text"
                  size="small"
                  v-bind="props"
                  @click="loadUserDefaults"
                  :loading="isLoadingDefaults"
                >
                  <v-icon>mdi-download</v-icon>
                </v-btn>
              </template>
              <div style="max-width: 260px;">
                Загрузить дефолты из личных настроек.<br>
                <span class="text-caption" style="opacity:.75;">Нормо-часы и цены операций не затрагиваются.</span>
              </div>
            </v-tooltip>
            <v-btn icon variant="text" size="small" @click="handleCloseRequest">
              <v-icon>mdi-close</v-icon>
            </v-btn>
          </div>
        </div>

        <!-- Body + Footer: SettingsShell handles sidebar/tabs/content, footer slot handles actions -->
        <SettingsShell
          class="flex-grow-1"
          :sections="settingsSections"
          v-model="activeSettingsSection"
          :is-mobile="isMobile"
          :nav-width="220"
        >
            <v-form>
              <!-- Section 0: Основное -->
              <div v-if="activeSettingsSection === 0" class="psm-section">
                <div class="psm-section-title">Основное</div>
                <div class="psm-section-hint">Базовые сведения о проекте (дела), объекте и эксперте</div>
                <v-card variant="outlined" class="psm-content-card">
                  <v-card-text>
                    <v-row dense>
                      <v-col cols="12" md="6">
                        <v-text-field v-model="projectData.number" label="№ дела" />
                      </v-col>
                      <v-col cols="12" md="6">
                        <v-text-field v-model="projectData.expert_name" label="ФИО эксперта" />
                      </v-col>
                      <v-col cols="12">
                        <v-text-field v-model="projectData.address" label="Адрес объекта" />
                      </v-col>
                    </v-row>
                  </v-card-text>
                </v-card>

                <div class="psm-section-title mt-6">Методика и регион</div>
                <div class="psm-section-hint">Влияет на расчёт ставок по профилям нормируемых работ</div>
                <v-card variant="outlined" class="psm-content-card">
                  <v-card-text>
                    <v-row dense>
                      <v-col cols="12" md="6">
                        <v-autocomplete
                          v-model="projectData.region_id"
                          :items="regions"
                          item-title="name"
                          item-value="id"
                          label="Регион"
                          clearable
                          density="compact"
                          hint="Используется для расчёта ставок по профилям"
                          :menu-props="{ maxHeight: 300 }"
                        />
                        <div v-if="!projectData.region_id" class="text-warning text-caption mt-2 d-flex align-center" style="gap: 6px;">
                          <v-icon size="small">mdi-alert-circle-outline</v-icon>
                          <span>Регион не выбран. Ставки будут расчитаны по умолчанию.</span>
                        </div>
                      </v-col>
                    </v-row>
                  </v-card-text>
                </v-card>
              </div>

              <!-- Section 1: Коэффициенты -->
              <div v-if="activeSettingsSection === 1" class="psm-section">
                <div class="psm-section-title">Коэффициенты</div>
                <div class="psm-section-hint">Применяются при расчёте стоимости материалов</div>
                <v-card variant="outlined" class="psm-content-card">
                  <v-card-text>
                    <v-row dense>
                      <v-col cols="12" md="6">
                        <v-text-field
                          v-model.number="projectData.waste_coefficient"
                          label="Коэффициент обрезков"
                          type="number"
                          min="1"
                          step="0.01"
                          hint="1.00 = без изменения"
                          persistent-hint
                        />
                      </v-col>
                      <v-col cols="12" md="6">
                        <v-text-field
                          v-model.number="projectData.repair_coefficient"
                          label="Ремонтный коэффициент"
                          type="number"
                          min="1"
                          step="0.01"
                          hint="1.00 = без изменения"
                          persistent-hint
                        />
                      </v-col>
                    </v-row>

                    <v-divider class="my-4" />

                    <div class="mb-3">
                      <div class="d-flex align-center gap-3">
                        <span class="text-subtitle-2 psm-mode-label" :class="{ 'psm-mode-label--active': !projectData.use_area_calc_mode }">Расчёт по листам</span>
                        <v-switch v-model="projectData.use_area_calc_mode" hide-details density="compact" color="primary" />
                        <span class="text-subtitle-2 psm-mode-label" :class="{ 'psm-mode-label--active': projectData.use_area_calc_mode }">Расчёт по площади</span>
                      </div>
                      <div class="text-caption text-grey mt-2">Влияет на таблицу материалов и итоговую стоимость</div>
                    </div>
                  </v-card-text>
                </v-card>
              </div>

              <!-- Section 2: Материалы -->
              <div v-if="activeSettingsSection === 2" class="psm-section">
                <div class="psm-section-title">Материалы по умолчанию</div>
                <div class="psm-section-hint">Подставляются при добавлении новых позиций</div>
                <v-card variant="outlined" class="psm-content-card">
                  <v-card-text>
                    <v-row dense>
                      <v-col cols="12" md="6">
                        <v-autocomplete
                          v-model="projectData.default_plate_material_id"
                          :items="materials.filter(m => m.type === 'plate')"
                          item-title="name"
                          item-value="id"
                          label="Плитный материал"
                          clearable
                          density="compact"
                        />
                      </v-col>
                      <v-col cols="12" md="6">
                        <v-autocomplete
                          v-model="projectData.default_edge_material_id"
                          :items="materials.filter(m => m.type === 'edge')"
                          item-title="name"
                          item-value="id"
                          label="Кромочный материал"
                          clearable
                          density="compact"
                        />
                      </v-col>
                    </v-row>
                  </v-card-text>
                </v-card>

                <v-card variant="outlined" class="psm-content-card mt-4">
                  <v-card-text>
                    <div class="psm-section-title mb-1">Актуальность подтверждения цены</div>
                    <div class="psm-section-hint mb-3">Срок, в течение которого подтверждение цены считается действительным</div>
                    <v-text-field
                      v-model.number="projectData.price_confirmation_freshness_days"
                      label="Срок актуальности, дней"
                      type="number"
                      min="1"
                      max="365"
                      step="1"
                      density="compact"
                      hint="По умолчанию 7 дней"
                      persistent-hint
                      style="max-width: 200px;"
                    />
                  </v-card-text>
                </v-card>
              </div>

              <!-- Section 3: Отходы -->
              <div v-if="activeSettingsSection === 3" class="psm-section">
                <div class="psm-section-title">Коэффициенты отходов</div>
                <div class="psm-section-hint">Специфичные коэффициенты для каждого типа материала</div>
                <v-card variant="outlined" class="psm-content-card">
                  <v-card-text>
                    <div class="d-flex flex-column gap-4">
                      <!-- Плитные -->
                      <div class="psm-waste-row">
                        <span class="text-subtitle-2 font-weight-bold psm-waste-label">Плитные</span>
                        <v-text-field
                          v-model.number="projectData.waste_plate_coefficient"
                          type="number"
                          step="0.01"
                          min="1"
                          density="compact"
                          hide-details
                          class="psm-waste-field"
                          :placeholder="String(projectData.waste_coefficient || 1.2)"
                        />
                        <v-switch v-model="projectData.apply_waste_to_plate" hide-details density="compact" color="#86e975" label="Применять" class="flex-shrink-0" />
                        <v-switch
                          v-model="projectData.show_waste_plate_description"
                          :disabled="!projectData.waste_plate_description?.title && !projectData.waste_plate_description?.text"
                          hide-details
                          density="compact"
                          color="#86e975"
                          label="В отчёте"
                          class="flex-shrink-0"
                        />
                        <div class="flex-grow-1" />
                        <v-btn size="small" variant="outlined" @click="openCoefficientDescriptionDialog('plate')" class="flex-shrink-0">
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>Описание
                        </v-btn>
                      </div>

                      <!-- Кромка -->
                      <div class="psm-waste-row">
                        <span class="text-subtitle-2 font-weight-bold psm-waste-label">Кромка</span>
                        <v-text-field
                          v-model.number="projectData.waste_edge_coefficient"
                          type="number"
                          step="0.01"
                          min="1"
                          density="compact"
                          hide-details
                          class="psm-waste-field"
                          :placeholder="String(projectData.waste_coefficient || 1.1)"
                        />
                        <v-switch v-model="projectData.apply_waste_to_edge" hide-details density="compact" color="#86e975" label="Применять" class="flex-shrink-0" />
                        <v-switch
                          v-model="projectData.show_waste_edge_description"
                          :disabled="!projectData.waste_edge_description?.title && !projectData.waste_edge_description?.text"
                          hide-details
                          density="compact"
                          color="#86e975"
                          label="В отчёте"
                          class="flex-shrink-0"
                        />
                        <div class="flex-grow-1" />
                        <v-btn size="small" variant="outlined" @click="openCoefficientDescriptionDialog('edge')" class="flex-shrink-0">
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>Описание
                        </v-btn>
                      </div>

                      <!-- Операции -->
                      <div class="psm-waste-row">
                        <span class="text-subtitle-2 font-weight-bold psm-waste-label">Операции</span>
                        <v-text-field
                          v-model.number="projectData.waste_operations_coefficient"
                          type="number"
                          step="0.01"
                          min="1"
                          density="compact"
                          hide-details
                          class="psm-waste-field"
                          :placeholder="String(projectData.waste_coefficient || 1.0)"
                        />
                        <v-switch v-model="projectData.apply_waste_to_operations" hide-details density="compact" color="#86e975" label="Применять" class="flex-shrink-0" />
                        <v-switch
                          v-model="projectData.show_waste_operations_description"
                          :disabled="!projectData.waste_operations_description?.title && !projectData.waste_operations_description?.text"
                          hide-details
                          density="compact"
                          color="#86e975"
                          label="В отчёте"
                          class="flex-shrink-0"
                        />
                        <div class="flex-grow-1" />
                        <v-btn size="small" variant="outlined" @click="openCoefficientDescriptionDialog('operations')" class="flex-shrink-0">
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>Описание
                        </v-btn>
                      </div>
                    </div>
                  </v-card-text>
                </v-card>
              </div>

              <!-- Section 4: Справочные блоки -->
              <div v-if="activeSettingsSection === 4" class="psm-section">
                <div class="psm-section-title">Справочные блоки сметы</div>
                <div class="psm-section-hint">Дополнительные текстовые блоки в конце PDF-отчёта</div>

                <div class="mb-4">
                  <v-btn
                    v-if="!projectData.text_blocks || projectData.text_blocks.length < 10"
                    prepend-icon="mdi-plus"
                    color="secondary"
                    variant="outlined"
                    size="small"
                    @click="addTextBlock"
                  >
                    Добавить блок ({{ projectData.text_blocks ? projectData.text_blocks.length : 0 }}/10)
                  </v-btn>
                  <div v-else class="text-caption text-warning d-flex align-center gap-1">
                    <v-icon size="small">mdi-alert-circle-outline</v-icon>
                    <span>Достигнут максимум (10 блоков)</span>
                  </div>
                </div>

                <div v-if="projectData.text_blocks && projectData.text_blocks.length > 0" class="mb-4">
                  <div v-for="(block, index) in projectData.text_blocks" :key="index" class="mb-3">
                    <v-card variant="outlined">
                      <v-card-text>
                        <div class="d-flex align-center justify-space-between mb-2">
                          <div class="d-flex align-center gap-2">
                            <span class="text-caption font-weight-bold">Блок {{ index + 1 }}</span>
                            <v-chip
                              size="small"
                              :variant="block.enabled !== false ? 'tonal' : 'outlined'"
                              :color="block.enabled !== false ? 'success' : 'default'"
                            >
                              {{ block.enabled !== false ? 'Включен' : 'Отключен' }}
                            </v-chip>
                            <v-switch v-model="block.enabled" hide-details density="compact" color="primary" title="Включить/выключить блок в отчёте" />
                          </div>
                          <div class="d-flex gap-1">
                            <v-btn v-if="index > 0" icon size="x-small" color="info" variant="text" @click="moveTextBlockUp(index)" title="Переместить вверх">
                              <v-icon size="small">mdi-arrow-up</v-icon>
                            </v-btn>
                            <v-btn v-if="index < (projectData.text_blocks?.length || 0) - 1" icon size="x-small" color="info" variant="text" @click="moveTextBlockDown(index)" title="Переместить вниз">
                              <v-icon size="small">mdi-arrow-down</v-icon>
                            </v-btn>
                            <v-btn icon size="x-small" color="error" variant="text" @click="removeTextBlock(index)" title="Удалить блок">
                              <v-icon size="small">mdi-delete</v-icon>
                            </v-btn>
                          </div>
                        </div>
                        <v-text-field
                          v-model="block.title"
                          label="Заголовок блока"
                          variant="outlined"
                          density="compact"
                          placeholder="Например: Общие примечания, Гарантия"
                          counter="100"
                          maxlength="100"
                          class="mb-2"
                          :disabled="block.enabled === false"
                        />
                        <RichTextEditor
                          v-model="block.text"
                          label="Текст блока"
                          placeholder="Введите текст (максимум 10000 символов)"
                          :disabled="block.enabled === false"
                        />
                      </v-card-text>
                    </v-card>
                  </div>
                </div>
              </div>
            </v-form>

          <template #footer>
            <SettingsShellFooter
              :is-dirty="isDirty"
              :saving="isSaving"
              @save="saveSettings"
              @cancel="handleCloseRequest"
            />
          </template>
        </SettingsShell>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, inject } from 'vue'
import { useDisplay } from 'vuetify'
import RichTextEditor from '@/components/notifications/RichTextEditor.vue'
import type { AxiosInstance } from 'axios'
import SettingsShell from '@/components/settings/shell/SettingsShell.vue'
import SettingsShellFooter from '@/components/settings/shell/SettingsShellFooter.vue'

interface Project {
  id: number
  number: string
  expert_name: string
  address: string
  region_id?: number | null
  waste_coefficient: number
  repair_coefficient: number
  use_area_calc_mode?: boolean
  default_plate_material_id?: number | null
  default_edge_material_id?: number | null
  waste_plate_coefficient?: number | null
  waste_edge_coefficient?: number | null
  waste_operations_coefficient?: number | null
  apply_waste_to_plate?: boolean
  apply_waste_to_edge?: boolean
  apply_waste_to_operations?: boolean
  show_waste_plate_description?: boolean
  show_waste_edge_description?: boolean
  show_waste_operations_description?: boolean
  waste_plate_description?: CoefficientDescription | null
  waste_edge_description?: CoefficientDescription | null
  waste_operations_description?: CoefficientDescription | null
  text_blocks?: TextBlock[]
  price_confirmation_freshness_days?: number | null
  [key: string]: any
}

interface CoefficientDescription {
  title: string
  text: string
}

interface TextBlock {
  title: string
  text: string
  enabled?: boolean
}

interface Material {
  id: number
  name: string
  type: 'plate' | 'edge' | 'facade' | 'hardware'
}

interface Region {
  id: number
  name: string
}

const props = defineProps<{
  modelValue: boolean
  project: Project
  regions: Region[]
  materials: Material[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'saved': [project: Project]
  'cancelled': []
  'error': [error: string]
}>()

const axios = inject<AxiosInstance>('axios')
const { smAndDown } = useDisplay()
const isMobile = computed(() => smAndDown.value)

// Local state
const projectData = ref<Project>(JSON.parse(JSON.stringify(props.project)))
const activeSettingsSection = ref(0)
const isSaving = ref(false)
const isLoadingDefaults = ref(false)
const isDirty = ref(false)
const isSyncing = ref(false)
const showUnsavedDialog = ref(false)

// Coefficient description dialog
const coefficientDescriptionDialog = ref(false)
const editingCoefficientType = ref<'plate' | 'edge' | 'operations' | null>(null)
const coefficientDescriptionForm = ref<CoefficientDescription>({ title: '', text: '' })

const settingsSections = [
  { id: 0, title: 'Основное', icon: 'mdi-folder-settings' },
  { id: 1, title: 'Коэффициенты', icon: 'mdi-tune' },
  { id: 2, title: 'Материалы', icon: 'mdi-package-variant' },
  { id: 3, title: 'Отходы', icon: 'mdi-recycle' },
  { id: 4, title: 'Справочные блоки', icon: 'mdi-text-box-outline' },
]

const syncProjectData = (source: Project) => {
  isSyncing.value = true
  projectData.value = JSON.parse(JSON.stringify(source))
  isDirty.value = false
  setTimeout(() => { isSyncing.value = false }, 0)
}

// Watch parent project changes (only when modal is closed or no pending edits)
watch(() => props.project, (newProject) => {
  if (props.modelValue && isDirty.value) return
  syncProjectData(newProject)
}, { deep: true })

// Reset state when modal opens
watch(() => props.modelValue, (newValue) => {
  if (newValue) {
    activeSettingsSection.value = 0
    syncProjectData(props.project)
  } else {
    isDirty.value = false
    showUnsavedDialog.value = false
  }
})

// Track dirty state
watch(projectData, () => {
  if (!props.modelValue || isSyncing.value) return
  isDirty.value = true
}, { deep: true })

// Close handling
const handleCloseRequest = () => {
  if (isDirty.value) {
    showUnsavedDialog.value = true
  } else {
    closeModal()
  }
}

const handleEscKey = (event: KeyboardEvent) => {
  // Only handle if coefficient dialog is not open
  if (coefficientDescriptionDialog.value || showUnsavedDialog.value) return
  event.preventDefault()
  handleCloseRequest()
}

const closeModal = () => {
  emit('update:modelValue', false)
  emit('cancelled')
}

const discardAndClose = () => {
  showUnsavedDialog.value = false
  closeModal()
}

const saveAndClose = async () => {
  await saveSettings()
  if (!isSaving.value) {
    showUnsavedDialog.value = false
    closeModal()
  }
}

// Save
const saveSettings = async () => {
  if (isSaving.value) return
  isSaving.value = true
  try {
    emit('saved', projectData.value)
    isDirty.value = false
  } catch (err: any) {
    console.error('Error saving settings:', err)
    emit('error', err.message || 'Ошибка сохранения настроек')
  } finally {
    isSaving.value = false
  }
}

// Load user defaults whitelist
const DEFAULTS_WHITELIST: string[] = [
  'region_id',
  'use_area_calc_mode',
  'waste_coefficient',
  'repair_coefficient',
  'default_plate_material_id',
  'default_edge_material_id',
  'default_expert_name',
  'default_number',
  'waste_plate_coefficient',
  'waste_edge_coefficient',
  'waste_operations_coefficient',
  'apply_waste_to_plate',
  'apply_waste_to_edge',
  'apply_waste_to_operations',
  'waste_plate_description',
  'waste_edge_description',
  'waste_operations_description',
  'show_waste_plate_description',
  'show_waste_edge_description',
  'show_waste_operations_description',
  'text_blocks',
]

const loadUserDefaults = async () => {
  if (!axios) {
    emit('error', 'Ошибка: сервис запросов недоступен')
    return
  }
  isLoadingDefaults.value = true
  try {
    const { data: userSettings } = await axios.get('/api/user/settings')
    for (const key of DEFAULTS_WHITELIST) {
      if (key in userSettings && userSettings[key] != null) {
        ;(projectData.value as any)[key] = userSettings[key]
      }
    }
  } catch (err: any) {
    console.error('Error loading user settings:', err)
    emit('error', err.response?.data?.message || err.message || 'Ошибка загрузки настроек')
  } finally {
    isLoadingDefaults.value = false
  }
}

// Text block handlers
const addTextBlock = () => {
  if (!projectData.value.text_blocks) projectData.value.text_blocks = []
  if (projectData.value.text_blocks.length < 10) {
    projectData.value.text_blocks.push({ title: '', text: '', enabled: true })
  }
}

const removeTextBlock = (index: number) => {
  projectData.value.text_blocks?.splice(index, 1)
}

const moveTextBlockUp = (index: number) => {
  if (projectData.value.text_blocks && index > 0) {
    const arr = projectData.value.text_blocks
    ;[arr[index - 1], arr[index]] = [arr[index]!, arr[index - 1]!]
  }
}

const moveTextBlockDown = (index: number) => {
  if (projectData.value.text_blocks && index < projectData.value.text_blocks.length - 1) {
    const arr = projectData.value.text_blocks
    ;[arr[index], arr[index + 1]] = [arr[index + 1]!, arr[index]!]
  }
}

// Coefficient description handlers
const getCoefficientTypeLabel = () => {
  const labels: Record<string, string> = {
    plate: 'плитных материалов',
    edge: 'кромочных материалов',
    operations: 'операций',
  }
  return labels[editingCoefficientType.value || ''] || ''
}

const openCoefficientDescriptionDialog = (type: 'plate' | 'edge' | 'operations') => {
  editingCoefficientType.value = type
  const descKey = `waste_${type}_description` as keyof Project
  const current = projectData.value[descKey] as CoefficientDescription | null
  coefficientDescriptionForm.value = current
    ? { title: current.title, text: current.text }
    : { title: '', text: '' }
  coefficientDescriptionDialog.value = true
}

const saveCoefficientDescription = () => {
  if (!editingCoefficientType.value) return
  const type = editingCoefficientType.value
  const cleaned = {
    title: coefficientDescriptionForm.value.title.trim(),
    text: cleanText(coefficientDescriptionForm.value.text),
  }
  const descKey = `waste_${type}_description` as keyof Project
  const showKey = `show_waste_${type}_description` as keyof Project
  if (cleaned.title || cleaned.text) {
    ;(projectData.value as any)[descKey] = cleaned
    ;(projectData.value as any)[showKey] = true
  } else {
    ;(projectData.value as any)[descKey] = null
    ;(projectData.value as any)[showKey] = false
  }
  closeCoefficientDescriptionDialog()
}

const closeCoefficientDescriptionDialog = () => {
  coefficientDescriptionDialog.value = false
  editingCoefficientType.value = null
  coefficientDescriptionForm.value = { title: '', text: '' }
}

// Text utilities
const cleanText = (text: string): string => {
  let cleaned = text.replace(/<[^>]*>/g, '')
  cleaned = cleaned
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&')
  return cleaned
    .split('\n')
    .map(line => line.replace(/[ \t]+/g, ' ').trim())
    .filter(line => line.length > 0)
    .join('\n')
}

const normalizeText = (input: string): string =>
  input
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/\u00A0/g, ' ')
    .split('\n')
    .map(line => line.replace(/[ \t]+/g, ' ').trim())
    .filter(line => line.length > 0)
    .join('\n')
    .trim()

const cleanHtmlToText = (html: string): string => {
  try {
    let text = html
    text = text.replace(/<\/?(p|div|br\s*\/?|li|ul|ol|h[1-6])>/gi, '\n')
    text = text.replace(/<a[^>]*>(.*?)<\/a>/gi, '$1')
    text = text.replace(/<[^>]*>/g, '')
    const textarea = document.createElement('textarea')
    textarea.innerHTML = text
    text = textarea.value
    return text.replace(/\r\n/g, '\n').replace(/\n{3,}/g, '\n\n').replace(/[ \t]+/g, ' ').trim()
  } catch {
    return html.replace(/<[^>]*>/g, '').trim()
  }
}

const onPasteCoefficientDescription = (event: ClipboardEvent) => {
  try {
    event.preventDefault()
    const clipboard = event.clipboardData
    if (!clipboard) return
    const htmlData = clipboard.getData('text/html')
    const pasted = htmlData?.trim()
      ? cleanHtmlToText(htmlData)
      : clipboard.getData('text/plain') || ''
    const normalized = normalizeText(pasted)
    const existing = coefficientDescriptionForm.value.text
    coefficientDescriptionForm.value.text = cleanText(
      existing ? `${existing}\n\n${normalized}` : normalized
    )
  } catch (e) {
    console.error('Error during paste:', e)
  }
}
</script>

<style scoped>
/* === Project Settings Modal — all styles scoped under .psm-* namespace === */

/* Dialog card fills the dialog on desktop, full-screen on mobile */
.psm-card {
  height: 90vh;
  max-height: 90vh;
}

/* On mobile (fullscreen) the card must fill the viewport */
:deep(.v-dialog--fullscreen) .psm-card {
  height: 100%;
  max-height: 100%;
  border-radius: 0 !important;
}

/* Header */
.psm-header {
  min-height: 56px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

/* Section headings */
.psm-section-title {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 4px;
}

.psm-section-hint {
  font-size: 12px;
  opacity: 0.65;
  margin-bottom: 12px;
}

.psm-section {
  padding-bottom: 24px;
}

.psm-content-card {
  border-radius: 8px;
}

/* Mode toggle labels */
.psm-mode-label {
  opacity: 0.5;
  transition: opacity 0.15s;
}

.psm-mode-label--active {
  opacity: 1;
}

/* Waste rows */
.psm-waste-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.psm-waste-label {
  min-width: 80px;
}

.psm-waste-field {
  max-width: 100px;
  flex-shrink: 0;
}

/* Footer */

.psm-dirty-indicator {
  min-width: 0;
}

/* Mobile-specific overrides for fullscreen mode */
@media (max-width: 600px) {
  .psm-header {
    padding-top: max(12px, env(safe-area-inset-top));
    padding-left: 16px;
    padding-right: 16px;
  }

  .psm-waste-row {
    flex-wrap: wrap;
  }
}
</style>
