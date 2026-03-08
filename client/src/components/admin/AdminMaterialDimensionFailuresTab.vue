<template>
  <div>
    <v-card class="mb-4">
      <v-card-text>
        <v-row align="center" dense>
          <v-col cols="12" sm="3">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              label="Поиск кейсов"
              hide-details
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="materialTypeFilter"
              :items="materialTypeOptions"
              label="Тип материала"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="statusFilter"
              :items="statusOptions"
              label="Статус"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="12" sm="3">
            <v-text-field
              v-model="sourceFilter"
              label="Источник"
              hide-details
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="12" sm="2" class="text-right">
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-refresh" @click="loadList">
              Обновить
            </v-btn>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card :loading="loading">
      <v-data-table-server
        :headers="headers"
        :items="items"
        :items-length="total"
        :loading="loading"
        :page="page"
        :items-per-page="perPage"
        density="compact"
        no-data-text="Нет неразобранных кейсов"
        @update:page="page = $event; loadList()"
        @update:items-per-page="perPage = $event; loadList()"
      >
        <template #item.raw_text="{ item }">
          <div class="raw-preview">{{ compactText(item.raw_text) }}</div>
        </template>

        <template #item.status="{ item }">
          <v-chip :color="item.resolved_at ? 'success' : 'warning'" size="small" variant="tonal">
            {{ item.resolved_at ? 'Resolved' : 'Unresolved' }}
          </v-chip>
        </template>

        <template #item.last_seen_at="{ item }">
          {{ formatDate(item.last_seen_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn
              size="x-small"
              variant="text"
              icon="mdi-file-document-edit-outline"
              @click="openDetails(item)"
            />
            <v-btn
              size="x-small"
              variant="text"
              icon="mdi-plus-box-multiple-outline"
              color="primary"
              @click="startRuleFromFailure(item)"
            />
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <v-dialog v-model="detailsDialog" max-width="980" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          Кейс #{{ selected?.id }}
          <v-spacer />
          <v-chip :color="selected?.resolved_at ? 'success' : 'warning'" size="small" variant="tonal">
            {{ selected?.resolved_at ? 'Resolved' : 'Unresolved' }}
          </v-chip>
        </v-card-title>

        <v-card-text v-if="selected">
          <v-alert
            v-if="detailsError"
            type="error"
            variant="tonal"
            class="mb-3"
            closable
            @click:close="detailsError = ''"
          >
            {{ detailsError }}
          </v-alert>

          <v-row>
            <v-col cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">Исходный текст</div>
              <v-sheet border rounded class="pa-3 text-body-2 data-block">{{ selected.raw_text }}</v-sheet>
            </v-col>
            <v-col cols="12" md="6">
              <div class="text-caption text-medium-emphasis mb-1">Нормализованный текст</div>
              <v-sheet border rounded class="pa-3 text-body-2 data-block">{{ selected.normalized_text }}</v-sheet>
            </v-col>
          </v-row>

          <v-row class="mt-1">
            <v-col cols="12" md="3">
              <div class="text-caption text-medium-emphasis">Материал</div>
              <div class="text-body-2">{{ selected.material_type || '—' }}</div>
            </v-col>
            <v-col cols="12" md="3">
              <div class="text-caption text-medium-emphasis">Источник</div>
              <div class="text-body-2">{{ selected.source || '—' }}</div>
            </v-col>
            <v-col cols="12" md="3">
              <div class="text-caption text-medium-emphasis">Ошибка</div>
              <div class="text-body-2">{{ selected.parse_error_reason || '—' }}</div>
            </v-col>
            <v-col cols="12" md="3">
              <div class="text-caption text-medium-emphasis">Повторений</div>
              <div class="text-body-2">{{ selected.occurrences }}</div>
            </v-col>
          </v-row>

          <v-divider class="my-4" />

          <div class="text-subtitle-2 mb-2">Ручное разрешение кейса</div>
          <v-row>
            <v-col cols="4">
              <v-text-field
                v-model.number="resolution.resolved_length_mm"
                label="resolved_length_mm"
                density="compact"
                variant="outlined"
                type="number"
                min="1"
              />
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model.number="resolution.resolved_width_mm"
                label="resolved_width_mm"
                density="compact"
                variant="outlined"
                type="number"
                min="1"
              />
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model.number="resolution.resolved_thickness_mm"
                label="resolved_thickness_mm"
                density="compact"
                variant="outlined"
                type="number"
                min="0.1"
                step="0.1"
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="resolution.resolution_note"
                label="resolution_note"
                density="compact"
                variant="outlined"
                rows="2"
                auto-grow
              />
            </v-col>
          </v-row>

          <v-divider class="my-3" />

          <div class="text-subtitle-2 mb-2">Последний parse_result</div>
          <v-sheet border rounded class="pa-3">
            <pre class="result-pre">{{ renderLastResult(selected.last_result) }}</pre>
          </v-sheet>
        </v-card-text>

        <v-card-actions>
          <v-btn
            color="primary"
            variant="tonal"
            prepend-icon="mdi-plus-box-multiple-outline"
            @click="selected && startRuleFromFailure(selected)"
          >
            Создать правило из кейса
          </v-btn>
          <v-spacer />
          <v-btn variant="text" @click="detailsDialog = false">Закрыть</v-btn>
          <v-btn color="success" variant="tonal" :loading="savingResolution" @click="saveResolution">
            Сохранить resolution
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  adminMaterialDimensionsApi,
  type MaterialDimensionMaterialType,
  type MaterialDimensionParseFailure,
  type MaterialDimensionRulePreset,
} from '@/api/materialDimensions'

const emit = defineEmits<{
  (event: 'create-rule', preset: MaterialDimensionRulePreset): void
}>()

const materialTypeOptions = [
  { title: 'Плита (plate)', value: 'plate' },
  { title: 'Кромка (edge)', value: 'edge' },
  { title: 'Фурнитура (hardware)', value: 'hardware' },
  { title: 'Фасад (facade)', value: 'facade' },
  { title: 'Комплектующая (fitting)', value: 'fitting' },
]

const statusOptions = [
  { title: 'Только unresolved', value: 'unresolved' },
  { title: 'Только resolved', value: 'resolved' },
]

const headers = [
  { title: 'Raw text', key: 'raw_text', sortable: false, width: '40%' },
  { title: 'Причина', key: 'parse_error_reason', sortable: false, width: '16%' },
  { title: 'Повторения', key: 'occurrences', sortable: false, width: '10%' },
  { title: 'Статус', key: 'status', sortable: false, width: '12%' },
  { title: 'Последний раз', key: 'last_seen_at', sortable: false, width: '14%' },
  { title: '', key: 'actions', sortable: false, width: '8%' },
]

const loading = ref(false)
const savingResolution = ref(false)
const items = ref<MaterialDimensionParseFailure[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)

const search = ref('')
const materialTypeFilter = ref<MaterialDimensionMaterialType | null>(null)
const statusFilter = ref<'resolved' | 'unresolved' | null>('unresolved')
const sourceFilter = ref('')

const detailsDialog = ref(false)
const selected = ref<MaterialDimensionParseFailure | null>(null)
const detailsError = ref('')
const resolution = ref({
  resolved_length_mm: null as number | null,
  resolved_width_mm: null as number | null,
  resolved_thickness_mm: null as number | null,
  resolution_note: '',
})

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function debouncedLoad() {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  searchTimeout = setTimeout(() => loadList(), 350)
}

async function loadList() {
  loading.value = true
  try {
    const params: {
      search?: string
      material_type?: MaterialDimensionMaterialType
      source?: string
      status?: 'resolved' | 'unresolved'
      page: number
      per_page: number
    } = {
      page: page.value,
      per_page: perPage.value,
    }

    if (search.value.trim()) {
      params.search = search.value.trim()
    }
    if (materialTypeFilter.value) {
      params.material_type = materialTypeFilter.value
    }
    if (statusFilter.value) {
      params.status = statusFilter.value
    }
    if (sourceFilter.value.trim()) {
      params.source = sourceFilter.value.trim()
    }

    const response = await adminMaterialDimensionsApi.listFailures(params)
    items.value = response.data
    total.value = response.meta.total
  } catch (error) {
    console.error('Failed to load material dimension parse failures', error)
  } finally {
    loading.value = false
  }
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('ru-RU')
}

function compactText(text: string): string {
  if (text.length <= 120) return text
  return `${text.slice(0, 120)}...`
}

function openDetails(item: MaterialDimensionParseFailure) {
  selected.value = item
  detailsError.value = ''
  resolution.value = {
    resolved_length_mm: item.resolved_length_mm,
    resolved_width_mm: item.resolved_width_mm,
    resolved_thickness_mm: item.resolved_thickness_mm,
    resolution_note: item.resolution_note || '',
  }
  detailsDialog.value = true
}

async function saveResolution() {
  if (!selected.value) {
    return
  }

  savingResolution.value = true
  detailsError.value = ''
  try {
    const updated = await adminMaterialDimensionsApi.updateFailure(selected.value.id, {
      resolved_length_mm: resolution.value.resolved_length_mm,
      resolved_width_mm: resolution.value.resolved_width_mm,
      resolved_thickness_mm: resolution.value.resolved_thickness_mm,
      resolution_note: resolution.value.resolution_note.trim() || null,
    })

    selected.value = updated
    await loadList()
  } catch (error: any) {
    detailsError.value = error?.response?.data?.message || 'Не удалось сохранить resolution.'
    console.error('Failed to update parse failure', error)
  } finally {
    savingResolution.value = false
  }
}

function extractNumericHints(text: string): number[] {
  const matches = text.match(/\d+(?:[.,]\d+)?/g) || []
  return matches.map((token) => Number(token.replace(',', '.'))).filter((value) => Number.isFinite(value))
}

function buildPatternByNumbers(count: number): string {
  if (count >= 3) {
    return '(\\d{2,5}(?:[.,]\\d+)?)\\s*(?:x|х|/|\\*)\\s*(\\d{2,5}(?:[.,]\\d+)?)\\s*(?:x|х|/|\\*)\\s*(\\d{1,3}(?:[.,]\\d+)?)'
  }
  if (count === 2) {
    return '(\\d{2,5}(?:[.,]\\d+)?)\\s*(?:x|х|/|\\*)\\s*(\\d{2,5}(?:[.,]\\d+)?)'
  }
  return '(\\d{1,5}(?:[.,]\\d+)?)'
}

function buildPresetFromFailure(failure: MaterialDimensionParseFailure): MaterialDimensionRulePreset {
  const numericHints = extractNumericHints(failure.normalized_text)
  const captures: MaterialDimensionRulePreset['captures'] = {}
  const fixed: MaterialDimensionRulePreset['fixed'] = {}

  if (numericHints.length >= 3) {
    captures.length_mm = 1
    captures.width_mm = 2
    captures.thickness_mm = 3
  } else if (numericHints.length === 2) {
    captures.length_mm = 1
    captures.width_mm = 2
  } else if (numericHints.length === 1) {
    captures.thickness_mm = 1
  }

  if (failure.material_type && ['plate', 'edge'].includes(failure.material_type)) {
    const hasLength = captures.length_mm !== undefined || failure.resolved_length_mm !== null
    const hasWidth = captures.width_mm !== undefined || failure.resolved_width_mm !== null

    if (!hasLength && failure.resolved_length_mm !== null) {
      fixed.length_mm = failure.resolved_length_mm
    }
    if (!hasWidth && failure.resolved_width_mm !== null) {
      fixed.width_mm = failure.resolved_width_mm
    }
  }

  if (failure.resolved_thickness_mm !== null) {
    fixed.thickness_mm = failure.resolved_thickness_mm
  }

  const prefilledFields: string[] = [
    'name',
    'description',
    'material_type',
    'source',
    'example_input',
    'pattern',
  ]

  if (captures.length_mm !== undefined) prefilledFields.push('capture_length_mm')
  if (captures.width_mm !== undefined) prefilledFields.push('capture_width_mm')
  if (captures.thickness_mm !== undefined) prefilledFields.push('capture_thickness_mm')
  if (fixed.length_mm !== undefined) prefilledFields.push('fixed_length_mm')
  if (fixed.width_mm !== undefined) prefilledFields.push('fixed_width_mm')
  if (fixed.thickness_mm !== undefined) prefilledFields.push('fixed_thickness_mm')
  if (failure.resolved_length_mm !== null || numericHints[0] !== undefined) prefilledFields.push('expected_length_mm')
  if (failure.resolved_width_mm !== null || numericHints[1] !== undefined) prefilledFields.push('expected_width_mm')
  if (failure.resolved_thickness_mm !== null || numericHints[2] !== undefined) {
    prefilledFields.push('expected_thickness_mm')
  }

  return {
    name: `Rule from failure #${failure.id}`,
    description: failure.parse_error_reason ? `Auto-filled from parse failure: ${failure.parse_error_reason}` : 'Auto-filled from parse failure',
    material_type: failure.material_type,
    source: failure.source,
    example_input: failure.raw_text,
    expected_length_mm: failure.resolved_length_mm ?? (numericHints[0] ?? null),
    expected_width_mm: failure.resolved_width_mm ?? (numericHints[1] ?? null),
    expected_thickness_mm: failure.resolved_thickness_mm ?? (numericHints[2] ?? null),
    pattern: buildPatternByNumbers(numericHints.length),
    captures,
    fixed,
    from_failed_case: true,
    prefilled_fields: prefilledFields,
  }
}

function startRuleFromFailure(failure: MaterialDimensionParseFailure) {
  emit('create-rule', buildPresetFromFailure(failure))
}

function renderLastResult(value: Record<string, unknown> | null): string {
  if (!value) {
    return 'Нет данных'
  }
  return JSON.stringify(value, null, 2)
}

onMounted(() => {
  loadList()
})
</script>

<style scoped>
.raw-preview {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.data-block {
  min-height: 72px;
  white-space: pre-wrap;
  word-break: break-word;
}

.result-pre {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 12px;
  line-height: 1.4;
}
</style>
