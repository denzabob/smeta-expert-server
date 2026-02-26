<template>
  <v-dialog v-model="dialogVisible" max-width="1000" persistent scrollable>
    <v-card class="import-dialog-card">
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-file-import</v-icon>
        Импорт позиций из Excel/CSV
        <v-spacer />
        <v-btn icon variant="text" @click="closeDialog">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-divider />

      <v-stepper v-model="currentStep" :items="stepItems" alt-labels hide-actions class="import-stepper">
        <template v-slot:item.1>
          <v-card flat class="import-step-card">
            <v-card-text class="pa-6 import-step-content">
              <!-- File Upload Zone -->
              <div
                class="upload-zone pa-8 text-center"
                :class="{ 'drag-over': isDragOver, 'has-file': selectedFile }"
                @dragover.prevent="isDragOver = true"
                @dragleave.prevent="isDragOver = false"
                @drop.prevent="handleFileDrop"
                @click="triggerFileInput"
              >
                <input
                  ref="fileInputRef"
                  type="file"
                  accept=".xlsx,.xls,.csv"
                  style="display: none"
                  @change="handleFileSelect"
                />
                
                <v-icon size="64" :color="selectedFile ? 'success' : 'grey'">
                  {{ selectedFile ? 'mdi-file-check' : 'mdi-cloud-upload' }}
                </v-icon>
                
                <div class="mt-4 text-h6" v-if="!selectedFile">
                  Перетащите файл сюда или нажмите для выбора
                </div>
                <div class="mt-4 text-h6 text-success" v-else>
                  {{ selectedFile.name }}
                </div>
                
                <div class="mt-2 text-body-2 text-grey">
                  Поддерживаемые форматы: XLSX, XLS, CSV (до 10 МБ)
                </div>
              </div>

              <!-- CSV Options (shown when CSV file selected) -->
              <v-expand-transition>
                <div v-if="selectedFile && isCsvFile" class="mt-6">
                  <v-divider class="mb-4" />
                  <div class="text-subtitle-1 mb-3">Настройки CSV</div>
                  <v-row>
                    <v-col cols="6">
                      <v-select
                        v-model="csvOptions.encoding"
                        :items="encodingOptions"
                        label="Кодировка"
                        variant="outlined"
                        density="compact"
                      />
                    </v-col>
                    <v-col cols="6">
                      <v-select
                        v-model="csvOptions.delimiter"
                        :items="delimiterOptions"
                        label="Разделитель"
                        variant="outlined"
                        density="compact"
                      />
                    </v-col>
                  </v-row>
                </div>
              </v-expand-transition>

              <!-- Error display -->
              <v-alert v-if="uploadError" type="error" class="mt-4" closable @click:close="uploadError = ''">
                {{ uploadError }}
              </v-alert>
            </v-card-text>

            <v-card-actions class="pa-4 import-step-actions">
              <v-spacer />
              <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
              <v-btn
                color="primary"
                :disabled="!selectedFile"
                :loading="uploading"
                @click="uploadFile"
              >
                Загрузить
                <v-icon end>mdi-arrow-right</v-icon>
              </v-btn>
            </v-card-actions>
          </v-card>
        </template>

        <template v-slot:item.2>
          <v-card flat class="import-step-card">
            <v-card-text class="pa-6 import-step-content">
              <!-- Sheet and Header Row Selection -->
              <v-row class="mb-4">
                <v-col cols="4" v-if="hasMultipleSheets">
                  <v-select
                    v-model="selectedSheetIndex"
                    :items="sheetItems"
                    label="Лист"
                    variant="outlined"
                    density="compact"
                    @update:model-value="reloadPreview"
                  />
                </v-col>
                <v-col :cols="hasMultipleSheets ? 4 : 6">
                  <v-text-field
                    v-model.number="headerRowIndex"
                    label="Строка заголовка (0 = нет)"
                    type="number"
                    min="0"
                    variant="outlined"
                    density="compact"
                    @update:model-value="reloadPreviewDebounced"
                  />
                </v-col>
                <v-col :cols="hasMultipleSheets ? 4 : 6">
                  <v-select
                    v-model="unitsLength"
                    :items="unitsOptions"
                    label="Единицы длины"
                    variant="outlined"
                    density="compact"
                  />
                </v-col>
              </v-row>

              <!-- Column Mapping Header -->
              <div class="text-subtitle-1 mb-2">
                Сопоставление колонок
                <v-chip size="small" :color="mappingValid ? 'success' : 'warning'" class="ml-2">
                  {{ mappingValid ? 'Готово' : 'Выберите ширину и длину' }}
                </v-chip>
              </div>

              <!-- Preview Table with Mapping -->
              <div class="preview-table-container">
                <v-table density="compact" fixed-header class="preview-table">
                  <thead>
                    <!-- Mapping row -->
                    <tr class="mapping-row">
                      <th class="text-center" style="width: 50px">#</th>
                      <th
                        v-for="(col, idx) in previewData?.preview?.columns || []"
                        :key="'map-' + idx"
                        class="pa-1"
                        style="min-width: 120px"
                      >
                        <v-select
                          v-model="columnMappings[idx]"
                          :items="getMappingOptions(idx)"
                          variant="outlined"
                          density="compact"
                          hide-details
                          placeholder="—"
                          clearable
                          :bg-color="getColumnBgColor(columnMappings[idx] ?? null)"
                        />
                      </th>
                    </tr>
                    <!-- Header row from file -->
                    <tr class="mapping-header-row">
                      <th class="text-center text-grey">Строка</th>
                      <th
                        v-for="(col, idx) in previewData?.preview?.columns || []"
                        :key="'head-' + idx"
                        class="text-caption text-grey-darken-1"
                      >
                        {{ col.name_guess || `Колонка ${idx + 1}` }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, rowIdx) in previewData?.preview?.rows || []" :key="rowIdx">
                      <td class="text-center text-grey">{{ row.original_index + 1 }}</td>
                      <td
                        v-for="(cell, cellIdx) in row.cells"
                        :key="cellIdx"
                        :class="getCellClass(cellIdx)"
                      >
                        {{ formatCell(cell) }}
                      </td>
                    </tr>
                  </tbody>
                </v-table>
              </div>

              <!-- Additional Options -->
              <v-row class="mt-4">
                <v-col cols="6">
                  <v-checkbox
                    v-model="useDefaultQty"
                    label="Qty по умолчанию, если пусто"
                    density="compact"
                    hide-details
                  />
                </v-col>
                <v-col cols="6" v-if="useDefaultQty">
                  <v-text-field
                    v-model.number="defaultQtyValue"
                    label="Значение по умолчанию"
                    type="number"
                    min="1"
                    variant="outlined"
                    density="compact"
                    hide-details
                  />
                </v-col>
              </v-row>

              <!-- Default kind (panel / facade) -->
              <v-divider class="my-4" />
              <div class="text-subtitle-1 mb-2">Тип позиций по умолчанию</div>
              <v-row>
                <v-col cols="6">
                  <v-select
                    v-model="defaultKind"
                    :items="kindOptions"
                    label="Тип по умолчанию"
                    variant="outlined"
                    density="compact"
                    hint="Применяется ко всем строкам, если колонка «Тип» не замаплена"
                    persistent-hint
                  />
                </v-col>
                <v-col cols="6" v-if="defaultKind === 'facade' && !columnMappings.includes('price_item_code')">
                  <v-autocomplete
                    v-model="defaultFacadeMaterialId"
                    :items="facadeMaterialOptions"
                    item-title="name"
                    item-value="id"
                    label="Фасадный материал по умолчанию"
                    variant="outlined"
                    density="compact"
                    clearable
                    :loading="loadingFacadeMats"
                    @update:search="onFacadeMatSearch"
                    no-filter
                    hint="Назначить один материал на все фасадные позиции (опционально)"
                    persistent-hint
                  >
                    <template #item="{ props: itemProps, item }">
                      <v-list-item v-bind="itemProps">
                        <v-list-item-subtitle>
                          {{ item.raw.thickness_mm }}мм | {{ item.raw.finish_name || '—' }}
                          <span v-if="item.raw.price_per_unit" class="text-green"> | {{ item.raw.price_per_unit }} ₽/м²</span>
                        </v-list-item-subtitle>
                      </v-list-item>
                    </template>
                  </v-autocomplete>
                </v-col>
              </v-row>

              <!-- Validation errors -->
              <v-alert v-if="mappingError" type="error" class="mt-4" closable @click:close="mappingError = ''">
                {{ mappingError }}
              </v-alert>
            </v-card-text>

            <v-card-actions class="pa-4 import-step-actions">
              <v-btn variant="text" @click="currentStep = 1">
                <v-icon start>mdi-arrow-left</v-icon>
                Назад
              </v-btn>
              <v-spacer />
              <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
              <v-btn
                color="primary"
                :disabled="!mappingValid"
                :loading="savingMapping"
                @click="saveMappingAndProceed"
              >
                Далее
                <v-icon end>mdi-arrow-right</v-icon>
              </v-btn>
            </v-card-actions>
          </v-card>
        </template>

        <template v-slot:item.3>
          <v-card flat class="import-step-card">
            <v-card-text class="pa-6 import-step-content">
              <!-- Import Summary -->
              <div class="text-h6 mb-4">Подтверждение импорта</div>

              <!-- Mapping Summary -->
              <v-card variant="outlined" class="mb-4">
                <v-card-title class="text-subtitle-1">Сопоставление колонок</v-card-title>
                <v-card-text>
                  <v-row>
                    <v-col cols="4">
                      <div class="d-flex align-center">
                        <v-chip color="primary" size="small" class="mr-2">Ширина</v-chip>
                        <span>→ Колонка {{ getColumnNameForField('width') }}</span>
                      </div>
                    </v-col>
                    <v-col cols="4">
                      <div class="d-flex align-center">
                        <v-chip color="primary" size="small" class="mr-2">Длина</v-chip>
                        <span>→ Колонка {{ getColumnNameForField('length') }}</span>
                      </div>
                    </v-col>
                    <v-col cols="4">
                      <div class="d-flex align-center">
                        <v-chip color="secondary" size="small" class="mr-2">Кол-во</v-chip>
                        <span>→ {{ getColumnNameForField('qty') || 'Не выбрано' }}</span>
                      </div>
                    </v-col>
                  </v-row>
                </v-card-text>
              </v-card>

              <!-- Import Options Summary -->
              <v-card variant="outlined" class="mb-4">
                <v-card-title class="text-subtitle-1">Параметры импорта</v-card-title>
                <v-card-text>
                  <v-row>
                    <v-col cols="4">
                      <v-icon size="small" class="mr-1">mdi-ruler</v-icon>
                      Единицы: <strong>{{ unitsLength }}</strong>
                    </v-col>
                    <v-col cols="4">
                      <v-icon size="small" class="mr-1">mdi-table-row</v-icon>
                      Строка заголовка: <strong>{{ headerRowIndex === 0 ? '—' : headerRowIndex }}</strong>
                    </v-col>
                    <v-col cols="4">
                      <v-icon size="small" class="mr-1">mdi-numeric</v-icon>
                      Qty по умолч.: <strong>{{ useDefaultQty ? defaultQtyValue : '—' }}</strong>
                    </v-col>
                  </v-row>
                </v-card-text>
              </v-card>

              <!-- Preview of parsed data -->
              <v-card variant="outlined" class="mb-4" v-if="importPreview">
                <v-card-title class="text-subtitle-1">
                  Предпросмотр (первые 10 строк)
                </v-card-title>
                <v-card-text>
                  <div class="import-preview-table-container">
                    <v-table density="compact" fixed-header>
                      <thead>
                        <tr>
                          <th>Строка</th>
                          <th>Тип</th>
                          <th>Ширина (мм)</th>
                          <th>Длина (мм)</th>
                          <th>Кол-во</th>
                          <th>Название</th>
                          <th>Статус</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="item in importPreview.preview.items" :key="item.row">
                          <td>{{ item.row }}</td>
                          <td>
                            <v-chip :color="(item.parsed as any).kind === 'facade' ? 'deep-purple' : 'blue-grey'" size="x-small" variant="tonal">
                              {{ (item.parsed as any).kind === 'facade' ? 'Фасад' : 'Панель' }}
                            </v-chip>
                          </td>
                          <td>{{ item.parsed.width_mm ?? '—' }}</td>
                          <td>{{ item.parsed.length_mm ?? '—' }}</td>
                          <td>{{ item.parsed.qty ?? '—' }}</td>
                          <td>{{ (item.parsed as any).name || '—' }}</td>
                          <td>
                            <v-chip
                              :color="item.status === 'ok' ? 'success' : 'error'"
                              size="x-small"
                            >
                              {{ item.status === 'ok' ? 'OK' : item.error }}
                            </v-chip>
                          </td>
                        </tr>
                      </tbody>
                    </v-table>
                  </div>
                </v-card-text>
              </v-card>

              <!-- Import progress / result -->
              <v-expand-transition>
                <v-card v-if="importing || importResult" variant="outlined" class="mb-4">
                  <v-card-text>
                    <div v-if="importing" class="text-center py-4">
                      <v-progress-circular indeterminate color="primary" size="48" />
                      <div class="mt-4">Импорт выполняется...</div>
                    </div>
                    
                    <div v-else-if="importResult">
                      <v-alert
                        :type="importResult.errors_count > 0 ? 'warning' : 'success'"
                        variant="tonal"
                        class="mb-4"
                      >
                        <div class="d-flex align-center justify-space-between">
                          <span>
                            Создано позиций: <strong>{{ importResult.created_count }}</strong>
                          </span>
                          <span v-if="importResult.skipped_count > 0">
                            Пропущено: <strong>{{ importResult.skipped_count }}</strong>
                          </span>
                          <span v-if="importResult.errors_count > 0">
                            Ошибок: <strong>{{ importResult.errors_count }}</strong>
                          </span>
                        </div>
                      </v-alert>

                      <!-- Error details -->
                      <div v-if="importResult.errors?.length > 0">
                        <div class="text-subtitle-2 mb-2">Ошибки импорта:</div>
                        <v-virtual-scroll
                          :items="importResult.errors.slice(0, 20)"
                          height="150"
                          item-height="32"
                        >
                          <template v-slot:default="{ item }">
                            <div class="d-flex align-center text-body-2 py-1">
                              <v-chip size="x-small" color="error" class="mr-2">
                                Строка {{ item.row }}
                              </v-chip>
                              {{ item.reason }}
                            </div>
                          </template>
                        </v-virtual-scroll>
                        <div v-if="importResult.errors.length > 20" class="text-caption text-grey mt-1">
                          ... и ещё {{ importResult.errors.length - 20 }} ошибок
                        </div>
                      </div>
                    </div>
                  </v-card-text>
                </v-card>
              </v-expand-transition>

              <!-- Error display -->
              <v-alert v-if="importError" type="error" class="mt-4" closable @click:close="importError = ''">
                {{ importError }}
              </v-alert>
            </v-card-text>

            <v-card-actions class="pa-4 import-step-actions">
              <v-btn variant="text" @click="currentStep = 2" :disabled="importing">
                <v-icon start>mdi-arrow-left</v-icon>
                Назад
              </v-btn>
              <v-spacer />
              <v-btn v-if="importResult" color="primary" @click="finishImport">
                Закрыть
              </v-btn>
              <template v-else>
                <v-btn variant="text" @click="closeDialog" :disabled="importing">Отмена</v-btn>
                <v-btn
                  color="primary"
                  :loading="importing"
                  @click="runImport"
                >
                  <v-icon start>mdi-import</v-icon>
                  Добавить в отчет
                </v-btn>
              </template>
            </v-card-actions>
          </v-card>
        </template>
      </v-stepper>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import api from '@/api/axios'
import projectImportApi, { 
  type UploadResponse, 
  type ImportPreviewResponse,
  type ImportResult,
  type ColumnMapping
} from '@/api/projectImport'

const props = defineProps<{
  modelValue: boolean
  projectId: number
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'imported', result: ImportResult): void
}>()

// Dialog visibility
const dialogVisible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

// Stepper state
const currentStep = ref(1)
const stepItems = [
  { title: 'Файл', value: 1 },
  { title: 'Превью и маппинг', value: 2 },
  { title: 'Подтверждение', value: 3 }
]

// Step 1: File upload
const selectedFile = ref<File | null>(null)
const isDragOver = ref(false)
const uploading = ref(false)
const uploadError = ref('')
const importSessionId = ref<number | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)

const csvOptions = ref({
  encoding: 'UTF-8',
  delimiter: ','
})

const encodingOptions = [
  { title: 'UTF-8', value: 'UTF-8' },
  { title: 'Windows-1251', value: 'windows-1251' },
  { title: 'ISO-8859-1', value: 'ISO-8859-1' }
]

const delimiterOptions = [
  { title: 'Запятая (,)', value: ',' },
  { title: 'Точка с запятой (;)', value: ';' },
  { title: 'Табуляция', value: '\t' }
]

const isCsvFile = computed(() => {
  return selectedFile.value?.name.toLowerCase().endsWith('.csv')
})

// Step 2: Preview and mapping
const previewData = ref<UploadResponse | null>(null)
const selectedSheetIndex = ref(0)
const headerRowIndex = ref(0)
const unitsLength = ref<'mm' | 'cm' | 'm'>('mm')
const columnMappings = ref<(string | null)[]>([])
const savingMapping = ref(false)
const mappingError = ref('')
const useDefaultQty = ref(true)
const defaultQtyValue = ref(1)
const defaultKind = ref<'panel' | 'facade'>('panel')
const defaultFacadeMaterialId = ref<number | null>(null)
const facadeMaterialOptions = ref<any[]>([])
const loadingFacadeMats = ref(false)
let facadeMatSearchTimeout: ReturnType<typeof setTimeout> | null = null

const kindOptions = [
  { title: 'Панель', value: 'panel' },
  { title: 'Фасад', value: 'facade' },
]

const unitsOptions = [
  { title: 'Миллиметры (мм)', value: 'mm' },
  { title: 'Сантиметры (см)', value: 'cm' },
  { title: 'Метры (м)', value: 'm' }
]

const sheetItems = computed(() => {
  return (previewData.value?.meta?.sheets || []).map(s => ({
    title: s.name,
    value: s.index
  }))
})

const hasMultipleSheets = computed(() => {
  return (previewData.value?.meta?.sheets?.length ?? 0) > 1
})

const mappingValid = computed(() => {
  const hasWidth = columnMappings.value.includes('width')
  const hasLength = columnMappings.value.includes('length')
  return hasWidth && hasLength
})

// Step 3: Confirmation and import
const importPreview = ref<ImportPreviewResponse | null>(null)
const importing = ref(false)
const importError = ref('')
const importResult = ref<ImportResult | null>(null)
const importResultEmitted = ref(false)

// Debounce timer for preview reload
let previewDebounceTimer: ReturnType<typeof setTimeout> | null = null

// Methods

function handleFileDrop(event: DragEvent) {
  isDragOver.value = false
  const files = event.dataTransfer?.files
  if (files && files.length > 0 && files[0]) {
    selectFile(files[0])
  }
}

function handleFileSelect(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files && target.files.length > 0 && target.files[0]) {
    selectFile(target.files[0])
  }
}

function triggerFileInput() {
  fileInputRef.value?.click()
}

function selectFile(file: File) {
  // Validate file type
  const validExtensions = ['.xlsx', '.xls', '.csv']
  const fileName = file.name.toLowerCase()
  const isValid = validExtensions.some(ext => fileName.endsWith(ext))
  
  if (!isValid) {
    uploadError.value = 'Неподдерживаемый формат файла. Выберите XLSX, XLS или CSV.'
    return
  }

  // Validate file size (10MB)
  if (file.size > 10 * 1024 * 1024) {
    uploadError.value = 'Файл слишком большой. Максимальный размер: 10 МБ.'
    return
  }

  selectedFile.value = file
  uploadError.value = ''
}

async function uploadFile() {
  if (!selectedFile.value) return

  uploading.value = true
  uploadError.value = ''

  try {
    const options: Record<string, any> = {
      header_row_index: headerRowIndex.value - 1
    }
    
    if (isCsvFile.value) {
      options.csv_encoding = csvOptions.value.encoding
      options.csv_delimiter = csvOptions.value.delimiter
    }

    const response = await projectImportApi.upload(props.projectId, selectedFile.value, options)
    
    importSessionId.value = response.import_session_id
    previewData.value = response
    headerRowIndex.value = response.preview.header_row_index + 1
    selectedSheetIndex.value = response.preview.sheet_index
    
    // Initialize column mappings
    initColumnMappings(response.preview.columns.length)
    
    // Move to step 2
    currentStep.value = 2
  } catch (error: any) {
    uploadError.value = error.response?.data?.message || error.message || 'Ошибка загрузки файла'
  } finally {
    uploading.value = false
  }
}

function initColumnMappings(columnCount: number) {
  columnMappings.value = new Array(columnCount).fill(null)
  
  // Try to auto-detect mappings based on header names
  const columns = previewData.value?.preview?.columns || []
  columns.forEach((col, idx) => {
    const name = col.name_guess.toLowerCase()
    if (name.includes('ширина') || name.includes('width') || name === 'w') {
      if (!columnMappings.value.includes('width')) {
        columnMappings.value[idx] = 'width'
      }
    } else if (name.includes('длина') || name.includes('длинна') || name.includes('высота') || name.includes('length') || name === 'l' || name === 'h') {
      if (!columnMappings.value.includes('length')) {
        columnMappings.value[idx] = 'length'
      }
    } else if (name.includes('кол') || name.includes('qty') || name.includes('quantity') || name.includes('шт')) {
      if (!columnMappings.value.includes('qty')) {
        columnMappings.value[idx] = 'qty'
      }
    } else if (name.includes('назв') || name.includes('деталь') || name.includes('name')) {
      if (!columnMappings.value.includes('name')) {
        columnMappings.value[idx] = 'name'
      }
    } else if (name.includes('тип') || name === 'kind' || name.includes('вид')) {
      if (!columnMappings.value.includes('kind')) {
        columnMappings.value[idx] = 'kind'
      }
    } else if (name.includes('артикул') || name.includes('код') || name.includes('sku') || name.includes('price_item')) {
      if (!columnMappings.value.includes('price_item_code')) {
        columnMappings.value[idx] = 'price_item_code'
      }
    }
  })
}

function getMappingOptions(columnIndex: number) {
  const currentValue = columnMappings.value[columnIndex]
  
  const options = [
    { title: '—', value: null },
    { title: 'Ширина', value: 'width', disabled: columnMappings.value.includes('width') && currentValue !== 'width' },
    { title: 'Длина / Высота', value: 'length', disabled: columnMappings.value.includes('length') && currentValue !== 'length' },
    { title: 'Кол-во', value: 'qty', disabled: columnMappings.value.includes('qty') && currentValue !== 'qty' },
    { title: 'Название', value: 'name', disabled: columnMappings.value.includes('name') && currentValue !== 'name' },
    { title: 'Тип (панель/фасад)', value: 'kind', disabled: columnMappings.value.includes('kind') && currentValue !== 'kind' },
    { title: 'Артикул фасада', value: 'price_item_code', disabled: columnMappings.value.includes('price_item_code') && currentValue !== 'price_item_code' },
    { title: 'Игнор.', value: 'ignore' }
  ]
  
  return options
}

async function loadFacadeMaterials(search = '') {
  loadingFacadeMats.value = true
  try {
    const resp = await api.get('/api/facade-materials', { params: { per_page: 30, search: search || undefined } })
    facadeMaterialOptions.value = (resp.data.data || resp.data || []).map((m: any) => ({
      id: m.id,
      name: m.name || [m.metadata?.base_material, m.thickness_mm && `${m.thickness_mm}мм`, m.metadata?.finish_name].filter(Boolean).join(' / ') || `Фасад #${m.id}`,
      thickness_mm: m.metadata?.thickness_mm ?? m.thickness_mm,
      finish_name: m.metadata?.finish_name ?? m.metadata?.finish?.name,
      price_per_unit: m.price_per_unit,
    }))
  } catch (e) {
    console.error('Failed to load facade materials for import', e)
  } finally {
    loadingFacadeMats.value = false
  }
}

function onFacadeMatSearch(val: string) {
  if (facadeMatSearchTimeout) clearTimeout(facadeMatSearchTimeout)
  facadeMatSearchTimeout = setTimeout(() => loadFacadeMaterials(val || ''), 300)
}

// Load facade materials when defaultKind changes to facade
import { watch as vueWatch } from 'vue'
vueWatch(defaultKind, (val) => {
  if (val === 'facade' && facadeMaterialOptions.value.length === 0) {
    loadFacadeMaterials()
  }
})

function getColumnBgColor(mapping: string | null): string {
  switch (mapping) {
    case 'width':
    case 'length':
      return 'primary-lighten-4'
    case 'qty':
      return 'secondary-lighten-4'
    case 'name':
      return 'info-lighten-4'
    case 'kind':
    case 'price_item_code':
      return 'warning-lighten-4'
    case 'ignore':
      return 'grey-lighten-3'
    default:
      return ''
  }
}

function getCellClass(columnIndex: number): string {
  const mapping = columnMappings.value[columnIndex]
  switch (mapping) {
    case 'width':
    case 'length':
      return 'bg-primary-lighten-5'
    case 'qty':
      return 'bg-secondary-lighten-5'
    case 'name':
      return 'bg-info-lighten-5'
    case 'ignore':
      return 'text-grey'
    default:
      return ''
  }
}

function formatCell(cell: any): string {
  if (cell === null || cell === undefined) return ''
  return String(cell)
}

async function reloadPreview() {
  if (!importSessionId.value) return

  try {
    const response = await projectImportApi.getPreview(importSessionId.value, {
      sheet_index: selectedSheetIndex.value,
      header_row_index: headerRowIndex.value - 1
    })
    
    previewData.value = response
    
    // Re-initialize mappings if column count changed
    if (response.preview.columns.length !== columnMappings.value.length) {
      initColumnMappings(response.preview.columns.length)
    }
  } catch (error: any) {
    mappingError.value = error.response?.data?.message || 'Ошибка загрузки превью'
  }
}

function reloadPreviewDebounced() {
  if (previewDebounceTimer) {
    clearTimeout(previewDebounceTimer)
  }
  previewDebounceTimer = setTimeout(() => {
    reloadPreview()
  }, 500)
}

async function saveMappingAndProceed() {
  if (!importSessionId.value || !mappingValid.value) return

  savingMapping.value = true
  mappingError.value = ''

  try {
    // Build mapping array with proper type casting
    const mapping: ColumnMapping[] = columnMappings.value
      .map((field, index) => ({
        column_index: index,
        field: field as ColumnMapping['field']
      }))
      .filter((m): m is ColumnMapping => m.field !== null)

    await projectImportApi.saveMapping(importSessionId.value, {
      sheet_index: selectedSheetIndex.value,
      header_row_index: headerRowIndex.value - 1,
      options: {
        units_length: unitsLength.value,
        default_qty_if_empty: useDefaultQty.value ? defaultQtyValue.value : undefined,
        skip_empty_rows: true,
        default_kind: defaultKind.value,
        default_facade_material_id: defaultKind.value === 'facade' ? defaultFacadeMaterialId.value : undefined,
      },
      mapping
    })

    // Load import preview
    const preview = await projectImportApi.getImportPreview(importSessionId.value)
    importPreview.value = preview

    // Move to step 3
    currentStep.value = 3
  } catch (error: any) {
    const errorData = error.response?.data
    if (errorData?.errors) {
      // Format validation errors
      const messages = Object.values(errorData.errors).flat() as string[]
      mappingError.value = messages.join('; ')
    } else {
      mappingError.value = errorData?.message || 'Ошибка сохранения маппинга'
    }
  } finally {
    savingMapping.value = false
  }
}

function getColumnNameForField(field: string): string {
  const index = columnMappings.value.indexOf(field)
  if (index === -1) return ''
  
  const columns = previewData.value?.preview?.columns || []
  const col = columns[index]
  return col?.name_guess || `#${index + 1}`
}

async function runImport() {
  if (!importSessionId.value) return

  importing.value = true
  importError.value = ''
  importResult.value = null

  try {
    const response = await projectImportApi.run(props.projectId, importSessionId.value, 'append')
    importResult.value = response.result
    if (importResult.value && !importResultEmitted.value) {
      emit('imported', importResult.value)
      importResultEmitted.value = true
    }
  } catch (error: any) {
    importError.value = error.response?.data?.message || 'Ошибка импорта'
  } finally {
    importing.value = false
  }
}

function finishImport() {
  if (importResult.value && !importResultEmitted.value) {
    emit('imported', importResult.value)
    importResultEmitted.value = true
  }
  closeDialog()
}

function closeDialog() {
  // Cleanup
  if (importSessionId.value && !importResult.value) {
    // Optionally delete unfinished session
    projectImportApi.deleteSession(importSessionId.value).catch(() => {})
  }
  
  resetState()
  dialogVisible.value = false
}

function resetState() {
  currentStep.value = 1
  selectedFile.value = null
  isDragOver.value = false
  uploading.value = false
  uploadError.value = ''
  importSessionId.value = null
  previewData.value = null
  selectedSheetIndex.value = 0
  headerRowIndex.value = 0
  unitsLength.value = 'mm'
  columnMappings.value = []
  savingMapping.value = false
  mappingError.value = ''
  useDefaultQty.value = true
  defaultQtyValue.value = 1
  defaultKind.value = 'panel'
  defaultFacadeMaterialId.value = null
  facadeMaterialOptions.value = []
  importPreview.value = null
  importing.value = false
  importError.value = ''
  importResult.value = null
  importResultEmitted.value = false
  csvOptions.value = { encoding: 'UTF-8', delimiter: ',' }
}

// Watch for dialog close to reset state
watch(dialogVisible, (newVal) => {
  if (!newVal) {
    resetState()
  }
})
</script>

<style scoped>
.upload-zone {
  border: 2px dashed #ccc;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.upload-zone:hover {
  border-color: #1976d2;
  background-color: #f5f5f5;
}

.upload-zone.drag-over {
  border-color: #1976d2;
  background-color: #e3f2fd;
}

.upload-zone.has-file {
  border-color: #4caf50;
  border-style: solid;
}

.preview-table-container {
  max-height: 260px;
  overflow: auto;
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  position: relative;
}

.import-preview-table-container {
  max-height: 220px;
  overflow: auto;
  border: 1px solid #e0e0e0;
  border-radius: 4px;
}

.preview-table {
  font-size: 0.85rem;
}

.preview-table th,
.preview-table td {
  white-space: nowrap;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.import-dialog-card {
  height: 96vh;
  max-height: 96vh;
  display: flex;
  flex-direction: column;
}

.import-stepper {
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.import-step-card {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.import-step-content {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding-bottom: 120px;
}

.import-step-actions {
  border-top: 1px solid #eee;
  padding: 24px !important;
  position: sticky;
  bottom: 0;
  background: #fff;
  z-index: 3;
}

.mapping-row th {
  background-color: #fafafa;
  position: sticky;
  top: 0;
  z-index: 4;
}

.mapping-header-row th {
  background-color: #fafafa;
  position: sticky;
  top: 40px;
  z-index: 3;
}

.bg-primary-lighten-5 {
  background-color: rgb(var(--v-theme-primary), 0.08) !important;
}

.bg-secondary-lighten-5 {
  background-color: rgb(var(--v-theme-secondary), 0.08) !important;
}
</style>
