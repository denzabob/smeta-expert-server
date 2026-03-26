<template>
  <div>
    <v-card class="mb-4">
      <v-card-text>
        <v-row align="center" dense>
          <v-col cols="12" sm="3">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              label="Поиск паттернов"
              hide-details
              variant="outlined"
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="materialTypeFilter"
              :items="materialTypeOptions"
              label="Тип"
              hide-details
              variant="outlined"
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="targetFieldFilter"
              :items="targetFieldOptions"
              label="Поле"
              hide-details
              variant="outlined"
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
              variant="outlined"
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="12" sm="2">
            <v-text-field
              v-model="sourceFilter"
              label="Источник"
              hide-details
              variant="outlined"
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="12" sm="1" class="text-right">
            <v-btn color="primary" icon="mdi-plus" @click="openCreate" />
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
        variant="outlined"
        density="compact"
        no-data-text="Нет паттернов"
        @update:page="page = $event; loadList()"
        @update:items-per-page="perPage = $event; loadList()"
      >
        <template #item.is_active="{ item }">
          <v-chip :color="item.is_active ? 'success' : 'grey'" size="small" variant="tonal">
            {{ item.is_active ? 'Активен' : 'Отключен' }}
          </v-chip>
        </template>

        <template #item.scope="{ item }">
          <div class="text-body-2">
            <div>{{ materialTypeLabel(item.material_type) }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ targetFieldLabel(item.target_field) }} / {{ item.source || 'Любой source' }}
            </div>
          </div>
        </template>

        <template #item.pattern="{ item }">
          <div class="text-caption pattern-cell">/{{ item.pattern }}/{{ item.flags }}</div>
        </template>

        <template #item.updated_at="{ item }">
          {{ formatDate(item.updated_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn size="x-small" variant="text" icon="mdi-pencil-outline" @click="openEdit(item)" />
            <v-btn
              size="x-small"
              variant="text"
              :icon="item.is_active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline'"
              :color="item.is_active ? 'success' : 'grey'"
              :loading="togglingId === item.id"
              @click="toggleActive(item)"
            />
            <v-btn
              size="x-small"
              variant="text"
              icon="mdi-delete-outline"
              color="error"
              :loading="deletingId === item.id"
              @click="removePattern(item)"
            />
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <v-dialog v-model="dialog" max-width="960" persistent>
      <v-card>
        <v-card-title>{{ editingId ? 'Редактировать паттерн типа' : 'Новый паттерн типа' }}</v-card-title>

        <v-card-text>
          <v-alert
            v-if="formError"
            type="error"
            variant="tonal"
            class="mb-3"
            closable
            @click:close="formError = ''"
          >
            {{ formError }}
          </v-alert>

          <v-row>
            <v-col cols="12" md="6">
              <v-text-field v-model="form.name" label="Название *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" md="3">
              <v-select
                v-model="form.material_type"
                :items="materialTypeOptions"
                label="Тип материала *"
                variant="outlined"
                density="compact"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-text-field
                v-model="form.source"
                label="Source (опционально)"
                variant="outlined"
                density="compact"
                placeholder="chrome_ext"
              />
            </v-col>
            <v-col cols="12" md="8">
              <v-textarea
                v-model="form.description"
                label="Описание"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
              />
            </v-col>
            <v-col cols="6" md="2">
              <v-text-field
                v-model.number="form.priority"
                label="Priority"
                variant="outlined"
                density="compact"
                type="number"
                min="1"
              />
            </v-col>
            <v-col cols="6" md="2" class="d-flex align-center">
              <v-switch v-model="form.is_active" label="Активен" variant="outlined" density="compact" color="success" hide-details />
            </v-col>
          </v-row>

          <v-divider class="my-3" />

          <v-row>
            <v-col cols="12" md="4">
              <v-select
                v-model="form.target_field"
                :items="targetFieldOptions"
                label="Где искать *"
                variant="outlined"
                density="compact"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model="form.flags"
                label="Regex flags"
                variant="outlined"
                density="compact"
                placeholder="iu"
              />
            </v-col>
            <v-col cols="12" md="4" class="d-flex align-center">
              <v-switch
                v-model="form.use_normalized_text"
                label="Нормализовать текст"
                variant="outlined"
                density="compact"
                color="primary"
                hide-details
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="form.pattern"
                label="Regex паттерн *"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
              />
            </v-col>
            <v-col cols="12">
              <v-text-field
                v-model="form.example_input"
                label="Пример входной строки"
                variant="outlined"
                density="compact"
              />
            </v-col>
          </v-row>

          <v-divider class="my-3" />

          <div class="text-subtitle-2 mb-2">Тест паттерна</div>
          <v-row>
            <v-col cols="12" md="6">
              <v-textarea
                v-model="previewTitle"
                label="test_title"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-textarea
                v-model="previewUrl"
                label="test_url"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
              />
            </v-col>
            <v-col cols="12" class="d-flex align-center ga-2">
              <v-btn color="primary" variant="tonal" :loading="previewLoading" @click="runPreview">Проверить</v-btn>
            </v-col>
          </v-row>

          <v-alert
            v-if="previewError"
            type="error"
            variant="tonal"
            class="mb-3"
            closable
            @click:close="previewError = ''"
          >
            {{ previewError }}
          </v-alert>

          <v-card v-if="previewResult" variant="outlined">
            <v-card-text>
              <div class="d-flex align-center mb-2">
                <div class="text-subtitle-2">Результат preview</div>
                <v-spacer />
                <v-chip :color="previewResult.preview_result.matched ? 'success' : 'warning'" size="small" variant="tonal">
                  {{ previewResult.preview_result.matched ? 'MATCH' : 'NO MATCH' }}
                </v-chip>
              </div>

              <v-row>
                <v-col cols="12" md="4">
                  <div class="text-caption text-medium-emphasis">Тип / единица</div>
                  <div class="text-body-2">
                    {{ materialTypeLabel(previewResult.preview_result.material_type) }} / {{ previewResult.preview_result.unit }}
                  </div>
                </v-col>
                <v-col cols="12" md="4">
                  <div class="text-caption text-medium-emphasis">Matched value</div>
                  <div class="text-body-2">{{ previewResult.preview_result.matched_value || '—' }}</div>
                </v-col>
                <v-col cols="12" md="4">
                  <div class="text-caption text-medium-emphasis">Regex</div>
                  <div class="text-body-2 pattern-cell">{{ previewResult.preview_result.expression }}</div>
                </v-col>
                <v-col cols="12">
                  <div class="text-caption text-medium-emphasis">Haystack</div>
                  <v-sheet border rounded class="pa-2 text-body-2 pattern-cell">{{ previewResult.preview_result.haystack || '—' }}</v-sheet>
                </v-col>
              </v-row>
            </v-card-text>
          </v-card>

          <v-alert
            v-if="previewResult && hasConflicts(previewResult.conflicts)"
            type="warning"
            variant="tonal"
            class="mt-3"
          >
            <div class="text-subtitle-2 mb-1">Обнаружены конфликты</div>
            <div v-if="previewResult.conflicts.has_exact_duplicate" class="text-body-2">
              Найден дубликат: #{{ previewResult.conflicts.exact_duplicate?.id }} {{ previewResult.conflicts.exact_duplicate?.name }}
            </div>
            <div v-if="previewResult.conflicts.priority_conflicts.length > 0" class="text-body-2">
              Конфликт приоритета: {{ previewResult.conflicts.priority_conflicts.map((item) => `#${item.id}`).join(', ') }}
            </div>
          </v-alert>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" @click="savePattern">Сохранить</v-btn>
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
  type MaterialTypePattern,
  type MaterialTypePatternConflictInfo,
  type MaterialTypePatternPreviewResponse,
  type MaterialTypePatternTargetField,
  type UpsertMaterialTypePatternPayload,
} from '@/api/materialDimensions'

type StatusFilter = 'active' | 'disabled' | null

const loading = ref(false)
const saving = ref(false)
const previewLoading = ref(false)
const dialog = ref(false)
const formError = ref('')
const previewError = ref('')

const items = ref<MaterialTypePattern[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)

const search = ref('')
const materialTypeFilter = ref<MaterialDimensionMaterialType | null>(null)
const targetFieldFilter = ref<MaterialTypePatternTargetField | null>(null)
const statusFilter = ref<StatusFilter>(null)
const sourceFilter = ref('')

const editingId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const togglingId = ref<number | null>(null)
const previewResult = ref<MaterialTypePatternPreviewResponse | null>(null)

const previewTitle = ref('')
const previewUrl = ref('')

let debounceTimer: ReturnType<typeof setTimeout> | null = null

const headers = [
  { title: 'ID', key: 'id', width: 70 },
  { title: 'Статус', key: 'is_active', width: 130 },
  { title: 'Priority', key: 'priority', width: 90 },
  { title: 'Scope', key: 'scope' },
  { title: 'Pattern', key: 'pattern' },
  { title: 'Обновлено', key: 'updated_at', width: 180 },
  { title: 'Действия', key: 'actions', sortable: false, width: 120 },
]

const materialTypeOptions = [
  { title: 'Плита', value: 'plate' },
  { title: 'Кромка', value: 'edge' },
  { title: 'Фурнитура', value: 'hardware' },
  { title: 'Фасад', value: 'facade' },
  { title: 'Комплектующие', value: 'fitting' },
]

const targetFieldOptions = [
  { title: 'Только title', value: 'title' },
  { title: 'Только URL', value: 'url' },
  { title: 'Title + URL', value: 'title_or_url' },
]

const statusOptions = [
  { title: 'Активные', value: 'active' },
  { title: 'Отключенные', value: 'disabled' },
]

const form = ref({
  name: '',
  description: '',
  is_active: true,
  priority: 100,
  material_type: 'hardware' as MaterialDimensionMaterialType,
  source: '',
  rule_type: 'regex' as const,
  target_field: 'title' as MaterialTypePatternTargetField,
  pattern: '',
  flags: 'iu',
  use_normalized_text: true,
  example_input: '',
  expected_material_type: null as MaterialDimensionMaterialType | null,
})

function materialTypeLabel(type: string | null | undefined): string {
  return materialTypeOptions.find((item) => item.value === type)?.title || (type || 'Не задан')
}

function targetFieldLabel(value: string | null | undefined): string {
  return targetFieldOptions.find((item) => item.value === value)?.title || (value || 'title')
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—'
  }

  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU')
}

function debouncedLoad() {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(() => {
    page.value = 1
    loadList()
  }, 250)
}

async function loadList() {
  loading.value = true
  try {
    const response = await adminMaterialDimensionsApi.listTypePatterns({
      page: page.value,
      per_page: perPage.value,
      search: search.value || undefined,
      material_type: materialTypeFilter.value || undefined,
      target_field: targetFieldFilter.value || undefined,
      source: sourceFilter.value || undefined,
      is_active: statusFilter.value ? statusFilter.value === 'active' : undefined,
    })
    items.value = response.data
    total.value = response.meta.total
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  formError.value = ''
  previewError.value = ''
  previewResult.value = null
  previewTitle.value = ''
  previewUrl.value = ''
  form.value = {
    name: '',
    description: '',
    is_active: true,
    priority: 100,
    material_type: 'hardware',
    source: '',
    rule_type: 'regex',
    target_field: 'title',
    pattern: '',
    flags: 'iu',
    use_normalized_text: true,
    example_input: '',
    expected_material_type: null,
  }
  dialog.value = true
}

function openEdit(item: MaterialTypePattern) {
  editingId.value = item.id
  formError.value = ''
  previewError.value = ''
  previewResult.value = null
  previewTitle.value = item.example_input || ''
  previewUrl.value = ''
  form.value = {
    name: item.name,
    description: item.description || '',
    is_active: item.is_active,
    priority: item.priority,
    material_type: item.material_type,
    source: item.source || '',
    rule_type: item.rule_type,
    target_field: item.target_field,
    pattern: item.pattern,
    flags: item.flags || 'iu',
    use_normalized_text: item.use_normalized_text,
    example_input: item.example_input || '',
    expected_material_type: item.expected_material_type,
  }
  dialog.value = true
}

function closeDialog() {
  dialog.value = false
}

function toPayload(): UpsertMaterialTypePatternPayload {
  return {
    name: form.value.name.trim(),
    description: form.value.description.trim() || null,
    is_active: form.value.is_active,
    priority: Number(form.value.priority) || 100,
    material_type: form.value.material_type,
    source: form.value.source.trim() || null,
    rule_type: 'regex',
    target_field: form.value.target_field,
    pattern: form.value.pattern.trim(),
    flags: form.value.flags.trim() || 'iu',
    use_normalized_text: form.value.use_normalized_text,
    example_input: form.value.example_input.trim() || null,
    expected_material_type: form.value.expected_material_type,
  }
}

async function savePattern() {
  formError.value = ''
  saving.value = true
  try {
    const payload = toPayload()
    if (editingId.value) {
      await adminMaterialDimensionsApi.updateTypePattern(editingId.value, payload)
    } else {
      await adminMaterialDimensionsApi.createTypePattern(payload)
    }
    dialog.value = false
    await loadList()
  } catch (error: any) {
    formError.value = error?.response?.data?.message || error?.message || 'Не удалось сохранить паттерн'
  } finally {
    saving.value = false
  }
}

async function runPreview() {
  previewError.value = ''
  previewLoading.value = true
  try {
    const payload = toPayload()
    previewResult.value = await adminMaterialDimensionsApi.previewTypePattern(
      payload,
      previewTitle.value.trim(),
      previewUrl.value.trim() || undefined,
    )
  } catch (error: any) {
    previewError.value = error?.response?.data?.message || error?.message || 'Ошибка preview'
  } finally {
    previewLoading.value = false
  }
}

async function removePattern(item: MaterialTypePattern) {
  if (!confirm(`Удалить паттерн "${item.name}"?`)) {
    return
  }

  deletingId.value = item.id
  try {
    await adminMaterialDimensionsApi.deleteTypePattern(item.id)
    await loadList()
  } finally {
    deletingId.value = null
  }
}

async function toggleActive(item: MaterialTypePattern) {
  togglingId.value = item.id
  try {
    await adminMaterialDimensionsApi.updateTypePattern(item.id, {
      name: item.name,
      description: item.description,
      is_active: !item.is_active,
      priority: item.priority,
      material_type: item.material_type,
      source: item.source,
      rule_type: item.rule_type,
      target_field: item.target_field,
      pattern: item.pattern,
      flags: item.flags,
      use_normalized_text: item.use_normalized_text,
      example_input: item.example_input,
      expected_material_type: item.expected_material_type,
    })
    await loadList()
  } finally {
    togglingId.value = null
  }
}

function hasConflicts(conflicts: MaterialTypePatternConflictInfo): boolean {
  return Boolean(conflicts.has_exact_duplicate || conflicts.priority_conflicts.length > 0)
}

onMounted(() => {
  loadList()
})
</script>

<style scoped>
.pattern-cell {
  word-break: break-word;
}
</style>
