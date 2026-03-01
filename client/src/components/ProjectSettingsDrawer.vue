<template>
  <div>
    <!-- Settings Drawer -->
    <v-navigation-drawer
      :model-value="modelValue"
      @update:model-value="handleDrawerUpdate"
      location="right"
      :width="compactLayout ? '100vw' : 1200"
      temporary
      elevation="16"
      class="settings-drawer"
      :style="{
        maxWidth: compactLayout ? '100vw' : '95vw',
        '--settings-drawer-content-width': compactLayout ? '100vw' : 'min(1200px, 95vw)'
      }"
    >
      <v-card class="h-100 d-flex flex-column rounded-0">
        <!-- Header -->
        <v-card-title class="pa-4 border-b">
          <div class="d-flex align-center justify-space-between gap-3">
            <span>Настройки проекта</span>
            <div class="d-flex gap-2">
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
              <v-btn
                icon
                variant="text"
                size="small"
                @click.stop="handleCloseSettingsDrawer"
              >
                <v-icon>mdi-close</v-icon>
              </v-btn>
            </div>
          </div>
        </v-card-title>

        <!-- Main content: sidebar + content layout -->
        <div class="d-flex flex-grow-1 overflow-hidden settings-layout" :class="{ 'settings-layout--mobile': compactLayout }">
          <!-- Left sidebar with navigation -->
          <div class="settings-sidebar">
            <v-list
              :model-value="activeSettingsSection"
              @update:model-value="activeSettingsSection = $event"
              class="py-0"
            >
              <v-list-item
                v-for="(section, idx) in settingsSections"
                :key="idx"
                :value="idx"
                @click="activeSettingsSection = idx"
              >
                <template #prepend>
                  <v-icon :icon="section.icon" size="small" class="mr-3"></v-icon>
                </template>
                <v-list-item-title class="text-subtitle-2">
                  {{ section.title }}
                </v-list-item-title>
              </v-list-item>
            </v-list>
          </div>

          <!-- Right content panel -->
          <div class="settings-content">
            <v-form class="h-100 d-flex flex-column">
              <!-- Scrollable content area -->
              <div class="settings-content-scroll">
                <div v-if="activeSettingsSection === 0" class="section-content">
                  <!-- Основное -->
                  <div class="section-title">Основное</div>
                  <div class="section-hint">Базовые сведения о проекте (дела), объекте и эксперте</div>
                  
                  <v-card variant="outlined" class="content-card">
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

                  <!-- Методика и регион -->
                  <div class="section-title mt-6">Методика и регион</div>
                  <div class="section-hint">Влияет на расчёт ставок по профилям нормируемых работ</div>
                  
                  <v-card variant="outlined" class="content-card">
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
                          <div v-if="!projectData.region_id" class="text-warning text-caption mt-2" style="display: flex; align-items: center; gap: 6px;">
                            <v-icon size="small">mdi-alert-circle-outline</v-icon>
                            <span>Регион не выбран. Ставки будут расчитаны по умолчанию.</span>
                          </div>
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>
                </div>

                <div v-if="activeSettingsSection === 1" class="section-content">
                  <!-- Коэффициенты -->
                  <div class="section-title">Коэффициенты</div>
                  <div class="section-hint">Применяются при расчёте стоимости материалов</div>
                  
                  <v-card variant="outlined" class="content-card">
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

                      <v-divider class="my-4"></v-divider>

                      <div class="mb-3">
                        <div class="d-flex align-center gap-3">
                          <span class="text-subtitle-2" :style="{ color: !projectData.use_area_calc_mode ? '#1976d2' : '#666' }">Расчёт по листам</span>
                          <v-switch v-model="projectData.use_area_calc_mode" hide-details density="compact"  color="primary" />
                          <span class="text-subtitle-2" :style="{ color: projectData.use_area_calc_mode ? '#1976d2' : '#666' }">Расчёт по площади</span>
                        </div>
                        <div class="text-caption text-grey mt-2">
                          Влияет на таблицу материалов и итоговую стоимость
                        </div>
                      </div>
                    </v-card-text>
                  </v-card>
                </div>

                <div v-if="activeSettingsSection === 2" class="section-content">
                  <!-- Материалы по умолчанию -->
                  <div class="section-title">Материалы по умолчанию</div>
                  <div class="section-hint">Подставляются при добавлении новых позиций</div>
                  
                  <v-card variant="outlined" class="content-card">
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
                </div>

                <div v-if="activeSettingsSection === 3" class="section-content">
                  <!-- Коэффициенты отходов -->
                  <div class="section-title">Коэффициенты отходов</div>
                  <div class="section-hint">Специфичные коэффициенты для каждого типа материала</div>
                  
                  <v-card variant="outlined" class="content-card">
                    <v-card-text>
                      <div class="d-flex flex-column gap-4">
                        <!-- Плитные -->
                        <div class="d-flex align-center gap-3" style="flex-wrap: nowrap;">
                          <span class="text-subtitle-2 font-weight-bold" style="min-width: 80px;">Плитные</span>
                          <v-text-field
                            v-model.number="projectData.waste_plate_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            style="max-width: 100px; flex-shrink: 0;"
                            :placeholder="String(projectData.waste_coefficient || 1.2)"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch v-model="projectData.apply_waste_to_plate" hide-details density="compact"  color="primary" />
                            <span class="text-caption" style="min-width: max-content;">Применять</span>
                          </div>
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch
                              v-model="projectData.show_waste_plate_description"
                              :disabled="!projectData.waste_plate_description || (!projectData.waste_plate_description.title && !projectData.waste_plate_description.text)"
                              hide-details
                              density="compact"
                             color="primary" />
                            <span class="text-caption" style="min-width: max-content;">В отчёте</span>
                          </div>
                          <div style="flex-grow: 1;"></div>
                          <v-btn
                            size="small"
                            variant="outlined"
                            @click="openCoefficientDescriptionDialog('plate')"
                            title="Редактировать описание"
                            style="flex-shrink: 0;"
                          >
                            <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                            Описание
                          </v-btn>
                        </div>

                        <!-- Кромка -->
                        <div class="d-flex align-center gap-3" style="flex-wrap: nowrap;">
                          <span class="text-subtitle-2 font-weight-bold" style="min-width: 80px;">Кромка</span>
                          <v-text-field
                            v-model.number="projectData.waste_edge_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            style="max-width: 100px; flex-shrink: 0;"
                            :placeholder="String(projectData.waste_coefficient || 1.1)"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch v-model="projectData.apply_waste_to_edge" hide-details density="compact"  color="primary" />
                            <span class="text-caption" style="min-width: max-content;">Применять</span>
                          </div>
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch
                              v-model="projectData.show_waste_edge_description"
                              :disabled="!projectData.waste_edge_description || (!projectData.waste_edge_description.title && !projectData.waste_edge_description.text)"
                              hide-details
                              density="compact"
                             color="primary" />
                            <span class="text-caption" style="min-width: max-content;">В отчёте</span>
                          </div>
                          <div style="flex-grow: 1;"></div>
                          <v-btn
                            size="small"
                            variant="outlined"
                            @click="openCoefficientDescriptionDialog('edge')"
                            title="Редактировать описание"
                            style="flex-shrink: 0;"
                          >
                            <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                            Описание
                          </v-btn>
                        </div>

                        <!-- Операции -->
                        <div class="d-flex align-center gap-3" style="flex-wrap: nowrap;">
                          <span class="text-subtitle-2 font-weight-bold" style="min-width: 80px;">Операции</span>
                          <v-text-field
                            v-model.number="projectData.waste_operations_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            style="max-width: 100px; flex-shrink: 0;"
                            :placeholder="String(projectData.waste_coefficient || 1.0)"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch v-model="projectData.apply_waste_to_operations" hide-details density="compact"  color="primary" />
                            <span class="text-caption" style="min-width: max-content;">Применять</span>
                          </div>
                          <div class="d-flex align-center gap-1" style="flex-shrink: 0;">
                            <v-switch
                              v-model="projectData.show_waste_operations_description"
                              :disabled="!projectData.waste_operations_description || (!projectData.waste_operations_description.title && !projectData.waste_operations_description.text)"
                              hide-details
                              density="compact"
                             color="primary" />
                            <span class="text-caption" style="min-width: max-content;">В отчёте</span>
                          </div>
                          <div style="flex-grow: 1;"></div>
                          <v-btn
                            size="small"
                            variant="outlined"
                            @click="openCoefficientDescriptionDialog('operations')"
                            title="Редактировать описание"
                            style="flex-shrink: 0;"
                          >
                            <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                            Описание
                          </v-btn>
                        </div>
                      </div>
                    </v-card-text>
                  </v-card>
                </div>

                <div v-if="activeSettingsSection === 4" class="section-content">
                  <!-- Справочные блоки -->
                  <div class="section-title">Справочные блоки сметы</div>
                  <div class="section-hint">Дополнительные текстовые блоки в конце PDF-отчёта</div>
                  
                  <!-- Add block button panel at top -->
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
                              <v-switch
                                v-model="block.enabled"
                                hide-details
                                density="compact"
                                title="Включить/выключить блок в отчёте"
                               color="primary" />
                            </div>
                            <div class="d-flex gap-1">
                              <v-btn
                                v-if="index > 0"
                                icon
                                size="x-small"
                                color="info"
                                variant="text"
                                @click="moveTextBlockUp(index)"
                                title="Переместить вверх"
                              >
                                <v-icon size="small">mdi-arrow-up</v-icon>
                              </v-btn>
                              <v-btn
                                v-if="index < (projectData.text_blocks?.length || 0) - 1"
                                icon
                                size="x-small"
                                color="info"
                                variant="text"
                                @click="moveTextBlockDown(index)"
                                title="Переместить вниз"
                              >
                                <v-icon size="small">mdi-arrow-down</v-icon>
                              </v-btn>
                              <v-btn
                                icon
                                size="x-small"
                                color="error"
                                variant="text"
                                @click="removeTextBlock(index)"
                                title="Удалить блок"
                              >
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
                            @input="block.title = block.title || ''"
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
              </div>
            </v-form>
          </div>
        </div>

      </v-card>
    </v-navigation-drawer>

    <!-- Coefficient Description Dialog -->
    <v-dialog v-model="coefficientDescriptionDialog" max-width="600">
      <v-card>
        <v-card-title>Редактировать описание для {{ getCoefficientTypeLabel() }}</v-card-title>
        <v-card-text>
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
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn variant="text" @click="closeCoefficientDescriptionDialog">
            Отменить
          </v-btn>
          <v-btn color="primary" variant="flat" @click="saveCoefficientDescription">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, inject } from 'vue'
import { useDisplay } from 'vuetify'
import RichTextEditor from '@/components/notifications/RichTextEditor.vue'

import type { AxiosInstance } from 'axios'

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
  type: 'plate' | 'edge' | 'facade'
}

interface Region {
  id: number
  name: string
}

// Props
const props = defineProps<{
  modelValue: boolean
  project: Project
  regions: Region[]
  materials: Material[]
}>()

// Emits
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'saved': [project: Project]
  'cancelled': []
  'error': [error: string]
}>()

// Get axios instance from parent app
const axios = inject<AxiosInstance>('axios')
const { mdAndDown } = useDisplay()
const compactLayout = computed(() => mdAndDown.value)

// Local state
const projectData = ref<Project>(JSON.parse(JSON.stringify(props.project)))
const activeSettingsSection = ref(0)
const isSaving = ref(false)
const isLoadingDefaults = ref(false)
const saveTimer = ref<number | null>(null)
const isDirty = ref(false)
const isSyncing = ref(false)

const syncProjectData = (source: Project) => {
  isSyncing.value = true
  projectData.value = JSON.parse(JSON.stringify(source))
  isDirty.value = false
  // дать watcher-ам отработать
  setTimeout(() => {
    isSyncing.value = false
  }, 0)
}

// Coefficient description dialog
const coefficientDescriptionDialog = ref(false)
const editingCoefficientType = ref<'plate' | 'edge' | 'operations' | null>(null)
const coefficientDescriptionForm = ref<CoefficientDescription>({
  title: '',
  text: ''
})

// Settings sections
const settingsSections = [
  { title: 'Основное', icon: 'mdi-folder-settings' },
  { title: 'Коэффициенты', icon: 'mdi-tune' },
  { title: 'Материалы', icon: 'mdi-package-variant' },
  { title: 'Отходы', icon: 'mdi-recycle' },
  { title: 'Справочные блоки', icon: 'mdi-text-box-outline' }
]

// Watch for changes
watch(() => props.project, (newProject) => {
  // не затираем ввод, если drawer открыт и есть несохранённые изменения
  if (props.modelValue && isDirty.value) return
  syncProjectData(newProject)
}, { deep: true })

watch(() => props.modelValue, (newValue) => {
  if (newValue) {
    activeSettingsSection.value = 0
    syncProjectData(props.project)
  } else {
    isDirty.value = false
  }
})

// Автосохранение при изменении данных
watch(projectData, () => {
  if (!props.modelValue) return // Не сохраняем если drawer закрыт
  if (isSyncing.value) return
  isDirty.value = true
  
  // Очищаем старый таймер
  if (saveTimer.value) {
    clearTimeout(saveTimer.value)
  }
  
  // Устанавливаем новый таймер для автосохранения с debounce
  saveTimer.value = window.setTimeout(() => {
    autoSaveSettings()
  }, 1000) // Сохраняем через 1 секунду после последнего изменения
}, { deep: true })

// Handlers
const handleDrawerUpdate = (value: boolean) => {
  emit('update:modelValue', value)
  if (!value) {
    // При закрытии drawer'а показываем уведомление об применении
    emit('cancelled')
  }
}

const handleCloseSettingsDrawer = () => {
  emit('update:modelValue', false)
  emit('cancelled')
}

const autoSaveSettings = async () => {
  if (isSaving.value) return

  isSaving.value = true
  try {
    // The parent component will handle the API call
    emit('saved', projectData.value)
  } catch (err: any) {
    console.error('Error saving settings:', err)
    emit('error', err.message || 'Ошибка сохранения настроек')
  } finally {
    isSaving.value = false
  }
}

/**
 * Белый список полей, которые копируются из user_settings в проект.
 * Нормо-часы, цены операций, номер дела, ФИО, адрес — НЕ входят.
 */
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

// Загрузить дефолты из личных настроек пользователя
const loadUserDefaults = async () => {
  if (!axios) {
    console.error('Axios instance not available')
    emit('error', 'Ошибка: сервис запросов недоступен')
    return
  }

  isLoadingDefaults.value = true
  try {
    const { data: userSettings } = await axios.get('/api/user/settings')

    // Применить СТРОГО по белому списку — исключает перетирание проектных полей
    for (const key of DEFAULTS_WHITELIST) {
      if (key in userSettings && userSettings[key] != null) {
        ;(projectData.value as any)[key] = userSettings[key]
      }
    }

    // Успешно загружены дефолты - закроем drawer
    emit('update:modelValue', false)
    emit('cancelled')
  } catch (err: any) {
    console.error('Error loading user settings:', err)
    emit('error', err.response?.data?.message || err.message || 'Ошибка загрузки настроек')
  } finally {
    isLoadingDefaults.value = false
  }
}

// Text block handlers
const addTextBlock = () => {
  if (!projectData.value.text_blocks) {
    projectData.value.text_blocks = []
  }
  if (projectData.value.text_blocks.length < 10) {
    projectData.value.text_blocks.push({ title: '', text: '', enabled: true })
  }
}

const removeTextBlock = (index: number) => {
  if (projectData.value.text_blocks) {
    projectData.value.text_blocks.splice(index, 1)
  }
}

const moveTextBlockUp = (index: number) => {
  if (projectData.value.text_blocks && index > 0) {
    const temp = projectData.value.text_blocks[index]!
    projectData.value.text_blocks[index] = projectData.value.text_blocks[index - 1]!
    projectData.value.text_blocks[index - 1] = temp
  }
}

const moveTextBlockDown = (index: number) => {
  if (projectData.value.text_blocks && index < projectData.value.text_blocks.length - 1) {
    const temp = projectData.value.text_blocks[index]!
    projectData.value.text_blocks[index] = projectData.value.text_blocks[index + 1]!
    projectData.value.text_blocks[index + 1] = temp
  }
}

// Coefficient description handlers
const getCoefficientTypeLabel = () => {
  const labels: Record<string, string> = {
    plate: 'плитных материалов',
    edge: 'кромочных материалов',
    operations: 'операций'
  }
  return labels[editingCoefficientType.value || ''] || ''
}

const openCoefficientDescriptionDialog = (type: 'plate' | 'edge' | 'operations') => {
  editingCoefficientType.value = type
  
  let currentDescription: CoefficientDescription | null = null
  
  if (type === 'plate') {
    currentDescription = projectData.value.waste_plate_description || null
  } else if (type === 'edge') {
    currentDescription = projectData.value.waste_edge_description || null
  } else if (type === 'operations') {
    currentDescription = projectData.value.waste_operations_description || null
  }
  
  coefficientDescriptionForm.value = currentDescription
    ? { title: currentDescription.title, text: currentDescription.text }
    : { title: '', text: '' }
  
  coefficientDescriptionDialog.value = true
}

const saveCoefficientDescription = () => {
  if (!editingCoefficientType.value) return
  
  const cleanedForm = {
    title: coefficientDescriptionForm.value.title.trim(),
    text: cleanText(coefficientDescriptionForm.value.text)
  }
  
  if (editingCoefficientType.value === 'plate') {
    if (cleanedForm.title || cleanedForm.text) {
      projectData.value.waste_plate_description = cleanedForm
      projectData.value.show_waste_plate_description = true
    } else {
      projectData.value.waste_plate_description = null
      projectData.value.show_waste_plate_description = false
    }
  } else if (editingCoefficientType.value === 'edge') {
    if (cleanedForm.title || cleanedForm.text) {
      projectData.value.waste_edge_description = cleanedForm
      projectData.value.show_waste_edge_description = true
    } else {
      projectData.value.waste_edge_description = null
      projectData.value.show_waste_edge_description = false
    }
  } else if (editingCoefficientType.value === 'operations') {
    if (cleanedForm.title || cleanedForm.text) {
      projectData.value.waste_operations_description = cleanedForm
      projectData.value.show_waste_operations_description = true
    } else {
      projectData.value.waste_operations_description = null
      projectData.value.show_waste_operations_description = false
    }
  }
  
  closeCoefficientDescriptionDialog()
}

const closeCoefficientDescriptionDialog = () => {
  coefficientDescriptionDialog.value = false
  editingCoefficientType.value = null
  coefficientDescriptionForm.value = { title: '', text: '' }
}

const toggleCoefficientDescription = (type: 'plate' | 'edge' | 'operations', enabled: boolean) => {
  if (type === 'plate') {
    projectData.value.show_waste_plate_description = enabled
  } else if (type === 'edge') {
    projectData.value.show_waste_edge_description = enabled
  } else if (type === 'operations') {
    projectData.value.show_waste_operations_description = enabled
  }
}

// Text utilities
const cleanText = (text: string): string => {
  let cleaned = text.replace(/<[^>]*>/g, '')
  cleaned = cleaned
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&')
  
  cleaned = cleaned
    .split('\n')
    .map(line => line.replace(/[ \t]+/g, ' ').trim())
    .filter(line => line.length > 0)
    .join('\n')
  
  return cleaned
}

const normalizeText = (input: string): string => {
  return input
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/\u00A0/g, ' ')
    .split('\n')
    .map(line => line.replace(/[ \t]+/g, ' ').trim())
    .filter(line => line.length > 0)
    .join('\n')
    .trim()
}

const cleanHtmlToText = (html: string): string => {
  try {
    let text = html
    text = text.replace(/<\/?(p|div|br\s*\/?|li|ul|ol|h[1-6])>/gi, '\n')
    text = text.replace(/<a[^>]*>(.*?)<\/a>/gi, '$1')
    text = text.replace(/<[^>]*>/g, '')
    
    const textarea = document.createElement('textarea')
    textarea.innerHTML = text
    text = textarea.value
    
    text = text
      .replace(/\r\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/[ \t]+/g, ' ')
      .trim()
    
    return text
  } catch (e) {
    console.error('Error cleaning HTML:', e)
    return html.replace(/<[^>]*>/g, '').trim()
  }
}

const onPasteCoefficientDescription = (event: ClipboardEvent) => {
  try {
    event.preventDefault()
    
    const clipboard = event.clipboardData
    if (!clipboard) return
    
    let pasted = ''
    const htmlData = clipboard.getData('text/html')
    
    if (htmlData && htmlData.trim().length > 0) {
      pasted = cleanHtmlToText(htmlData)
    } else {
      pasted = clipboard.getData('text/plain') || ''
    }
    
    const normalized = normalizeText(pasted)
    coefficientDescriptionForm.value.text = (coefficientDescriptionForm.value.text ? coefficientDescriptionForm.value.text + '\n\n' : '') + normalized
    coefficientDescriptionForm.value.text = cleanText(coefficientDescriptionForm.value.text)
  } catch (e) {
    console.error('Error during paste:', e)
  }
}
</script>

<style scoped>
/* Settings Drawer Styles */
:deep(.settings-drawer .v-navigation-drawer__content) {
  width: var(--settings-drawer-content-width, min(1200px, 95vw)) !important;
  max-width: 100vw;
}

/* Sidebar + Content Layout */
.settings-sidebar {
  width: 280px;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  overflow-y: auto;
  flex-shrink: 0;
}

:deep(.settings-sidebar .v-list) {
  padding-top: 0;
  padding-bottom: 0;
}

:deep(.settings-sidebar .v-list-item) {
  border-radius: 0;
}

:deep(.settings-sidebar .v-list-item--active) {
  background-color: rgba(var(--v-theme-primary), 0.08);
  border-right: 3px solid rgb(var(--v-theme-primary));
}

:deep(.settings-sidebar .v-list-item__prepend) {
  margin-inline-end: 12px;
}

.settings-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.settings-content-scroll {
  overflow-y: auto;
  flex: 1;
  padding: 24px;
}

.section-content {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: rgba(var(--v-theme-on-surface), 0.87);
  margin-bottom: 4px;
}

.section-hint {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 16px;
}

.content-card {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

@media (max-width: 960px) {
  .settings-layout--mobile {
    flex-direction: column;
  }

  .settings-layout--mobile .settings-sidebar {
    width: 100%;
    max-width: 100%;
    max-height: 84px;
    border-right: 0;
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
    overflow-x: auto;
    overflow-y: hidden;
  }

  .settings-layout--mobile :deep(.settings-sidebar .v-list) {
    display: flex;
    flex-wrap: nowrap;
    min-width: max-content;
  }

  .settings-layout--mobile :deep(.settings-sidebar .v-list-item) {
    min-width: max-content;
    border-bottom: 3px solid transparent;
  }

  .settings-layout--mobile :deep(.settings-sidebar .v-list-item--active) {
    border-right: 0;
    border-bottom-color: rgb(var(--v-theme-primary));
  }

  .settings-content-scroll {
    padding: 16px;
  }
}
</style>

