<template>
  <div class="problems-view">
    <div class="problems-main" :class="{ 'problems-main--with-panel': selectedItem }">
      <!-- Header -->
      <div class="view-header">
        <div>
          <h2 class="text-h5 font-weight-medium mb-1">Проблемные случаи</h2>
          <p class="text-body-2 text-medium-emphasis">
            Материалы, которые не удалось распознать автоматически
          </p>
        </div>
        <v-spacer />
        <v-btn
          v-if="selectedItem"
          variant="text"
          @click="closeInspector"
        >
          <v-icon icon="mdi-close" class="mr-1" />
          Закрыть панель
        </v-btn>
      </div>

      <!-- Filters -->
      <v-card variant="outlined" class="mb-4">
        <v-card-text>
          <v-row align="center" dense>
            <v-col cols="12" sm="4">
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                label="Поиск по тексту"
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
                @update:model-value="loadList"
              />
            </v-col>
            <v-col cols="12" sm="2">
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
              <v-btn 
                variant="tonal" 
                color="primary" 
                prepend-icon="mdi-refresh" 
                @click="loadList"
              >
                Обновить
              </v-btn>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>

      <!-- Progress indicator -->
      <v-alert
        v-if="items.length > 0"
        type="info"
        variant="tonal"
        class="mb-4"
        density="compact"
      >
        <div class="d-flex align-center">
          <span>
            Показано {{ items.length }} из {{ total }} случаев
          </span>
          <v-spacer />
          <span v-if="selectedIndex >= 0" class="text-body-2">
            Текущий: {{ selectedIndex + 1 }} / {{ items.length }}
          </span>
        </div>
      </v-alert>

      <!-- Table -->
      <v-card variant="outlined" :loading="loading">
        <v-data-table-server
          v-model:items-per-page="perPage"
          :headers="headers"
          :items="items"
          :items-length="total"
          :loading="loading"
          :page="page"
          density="comfortable"
          class="problems-table"
          item-value="id"
          :row-props="getRowProps"
          @update:page="page = $event; loadList()"
          @update:items-per-page="perPage = $event; loadList()"
          @click:row="handleRowClick"
        >
          <template #item.raw_text="{ item }">
            <div class="text-truncate raw-text-cell" :title="item.raw_text">
              {{ item.raw_text }}
            </div>
          </template>

          <template #item.material_type="{ item }">
            <v-chip 
              v-if="item.material_type" 
              size="small" 
              variant="tonal"
            >
              {{ materialTypeLabel(item.material_type) }}
            </v-chip>
            <span v-else class="text-medium-emphasis">—</span>
          </template>

          <template #item.parse_error_reason="{ item }">
            <v-chip size="small" color="warning" variant="tonal">
              {{ translateErrorReason(item.parse_error_reason) }}
            </v-chip>
          </template>

          <template #item.occurrences="{ item }">
            <span class="text-medium-emphasis">{{ item.occurrences }}×</span>
          </template>

          <template #item.status="{ item }">
            <v-chip 
              :color="item.resolved_at ? 'success' : 'warning'" 
              size="small" 
              variant="tonal"
            >
              {{ item.resolved_at ? 'Исправлено' : 'Ожидает' }}
            </v-chip>
          </template>

          <template #item.actions="{ item }">
            <div class="d-flex gap-1">
              <v-btn
                size="small"
                variant="tonal"
                color="primary"
                @click.stop="selectItem(item)"
              >
                Открыть
              </v-btn>
            </div>
          </template>

          <template #no-data>
            <div class="text-center py-8 text-medium-emphasis">
              <v-icon icon="mdi-check-circle-outline" size="64" color="success" class="mb-3" />
              <div class="text-h6 mb-1">Все случаи обработаны</div>
              <div class="text-body-2">Нет проблемных случаев, требующих внимания</div>
            </div>
          </template>
        </v-data-table-server>
      </v-card>
    </div>

    <!-- Inspector Panel -->
    <transition name="slide-panel">
      <div v-if="selectedItem" class="problems-inspector">
        <div class="inspector-header">
          <h3 class="text-subtitle-1 font-weight-medium">
            Случай #{{ selectedItem.id }}
          </h3>
          <div class="d-flex gap-1">
            <v-btn
              icon="mdi-chevron-up"
              variant="text"
              size="small"
              :disabled="selectedIndex <= 0"
              @click="selectPrevious"
            />
            <v-btn
              icon="mdi-chevron-down"
              variant="text"
              size="small"
              :disabled="selectedIndex >= items.length - 1"
              @click="selectNext"
            />
            <v-btn
              icon="mdi-close"
              variant="text"
              size="small"
              @click="closeInspector"
            />
          </div>
        </div>

        <v-divider />

        <div class="inspector-content">
          <!-- Status -->
          <div class="inspector-section">
            <v-chip 
              :color="selectedItem.resolved_at ? 'success' : 'warning'" 
              variant="tonal"
              class="mb-3"
            >
              {{ selectedItem.resolved_at ? 'Исправлено' : 'Ожидает обработки' }}
            </v-chip>
          </div>

          <!-- Original Text -->
          <div class="inspector-section">
            <div class="section-label">Исходный текст</div>
            <v-sheet border rounded class="pa-3 text-body-2 mono-text">
              {{ selectedItem.raw_text }}
            </v-sheet>
          </div>

          <!-- Normalized Text -->
          <div class="inspector-section">
            <div class="section-label">Нормализованный текст</div>
            <v-sheet border rounded class="pa-3 text-body-2 mono-text">
              {{ selectedItem.normalized_text || selectedItem.raw_text }}
            </v-sheet>
          </div>

          <!-- Detected Numbers -->
          <div class="inspector-section">
            <div class="section-label">Обнаруженные числа</div>
            <div class="d-flex flex-wrap gap-2">
              <v-chip
                v-for="(num, idx) in extractedNumbers"
                :key="idx"
                size="small"
                color="primary"
                variant="outlined"
              >
                {{ num }}
              </v-chip>
              <span v-if="extractedNumbers.length === 0" class="text-medium-emphasis">
                Числа не найдены
              </span>
            </div>
          </div>

          <!-- Meta Info -->
          <v-row class="inspector-section">
            <v-col cols="6">
              <div class="section-label">Тип материала</div>
              <div class="text-body-2">
                {{ materialTypeLabel(selectedItem.material_type) || '—' }}
              </div>
            </v-col>
            <v-col cols="6">
              <div class="section-label">Источник</div>
              <div class="text-body-2">{{ selectedItem.source || '—' }}</div>
            </v-col>
            <v-col cols="6">
              <div class="section-label">Причина ошибки</div>
              <div class="text-body-2">
                {{ translateErrorReason(selectedItem.parse_error_reason) }}
              </div>
            </v-col>
            <v-col cols="6">
              <div class="section-label">Повторений</div>
              <div class="text-body-2">{{ selectedItem.occurrences }}</div>
            </v-col>
          </v-row>

          <v-divider class="my-4" />

          <!-- Quick Fix Section -->
          <div class="inspector-section">
            <div class="section-label">Быстрое исправление</div>
            <v-row dense>
              <v-col cols="4">
                <v-text-field
                  v-model.number="resolution.length"
                  label="Длина, мм"
                  type="number"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </v-col>
              <v-col cols="4">
                <v-text-field
                  v-model.number="resolution.width"
                  label="Ширина, мм"
                  type="number"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </v-col>
              <v-col cols="4">
                <v-text-field
                  v-model.number="resolution.thickness"
                  label="Толщина, мм"
                  type="number"
                  density="compact"
                  variant="outlined"
                  hide-details
                  step="0.1"
                />
              </v-col>
            </v-row>
            <v-textarea
              v-model="resolution.note"
              label="Примечание"
              density="compact"
              variant="outlined"
              rows="2"
              hide-details
              class="mt-2"
            />
          </div>

          <!-- Actions -->
          <div class="inspector-section">
            <v-alert
              v-if="saveError"
              type="error"
              variant="tonal"
              class="mb-3"
              closable
              @click:close="saveError = ''"
            >
              {{ saveError }}
            </v-alert>

            <div class="d-flex flex-column gap-2">
              <v-btn
                color="primary"
                variant="flat"
                block
                :loading="saving"
                @click="saveResolution"
              >
                <v-icon icon="mdi-check" class="mr-1" />
                Исправить и продолжить
              </v-btn>
              
              <v-btn
                color="secondary"
                variant="tonal"
                block
                @click="openRuleCreator"
              >
                <v-icon icon="mdi-plus" class="mr-1" />
                Создать правило
              </v-btn>

              <v-btn
                variant="text"
                block
                @click="skipAndNext"
              >
                Пропустить
              </v-btn>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <!-- Rule Creator Dialog -->
    <v-dialog v-model="ruleCreatorOpen" max-width="800" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          Создание правила
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="ruleCreatorOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <AdminRuleCreator
            v-if="selectedItem"
            :preset="rulePreset"
            @saved="handleRuleSaved"
            @cancel="ruleCreatorOpen = false"
          />
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  adminMaterialDimensionsApi,
  type MaterialDimensionParseFailure,
  type MaterialDimensionMaterialType
} from '@/api/materialDimensions'
import AdminRuleCreator from '@/components/admin/AdminRuleCreator.vue'

const route = useRoute()
const router = useRouter()

// List state
const loading = ref(false)
const saving = ref(false)
const saveError = ref('')
const items = ref<MaterialDimensionParseFailure[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)

// Filters
const search = ref('')
const materialTypeFilter = ref<MaterialDimensionMaterialType | null>(null)
const statusFilter = ref<'unresolved' | 'resolved' | null>('unresolved')
const sourceFilter = ref('')

// Selected item
const selectedItem = ref<MaterialDimensionParseFailure | null>(null)
const selectedIndex = computed(() => {
  if (!selectedItem.value) return -1
  return items.value.findIndex(i => i.id === selectedItem.value!.id)
})

// Resolution form
const resolution = ref({
  length: null as number | null,
  width: null as number | null,
  thickness: null as number | null,
  note: ''
})

// Rule creator
const ruleCreatorOpen = ref(false)
const rulePreset = computed(() => {
  if (!selectedItem.value) return null
  return {
    example_input: selectedItem.value.raw_text,
    material_type: selectedItem.value.material_type,
    source: selectedItem.value.source
  }
})

// Extracted numbers from selected item
const extractedNumbers = computed(() => {
  if (!selectedItem.value) return []
  const text = selectedItem.value.normalized_text || selectedItem.value.raw_text
  const matches = text.match(/\d+(?:[.,]\d+)?/g) || []
  return matches.map(m => parseFloat(m.replace(',', '.')))
})

const materialTypeOptions = [
  { title: 'Плита', value: 'plate' },
  { title: 'Кромка', value: 'edge' },
  { title: 'Фурнитура', value: 'hardware' },
  { title: 'Фасад', value: 'facade' },
  { title: 'Комплектующие', value: 'fitting' }
]

const statusOptions = [
  { title: 'Ожидает', value: 'unresolved' },
  { title: 'Исправлено', value: 'resolved' }
]

const headers = [
  { title: 'Исходный текст', key: 'raw_text', sortable: false },
  { title: 'Тип', key: 'material_type', sortable: false, width: 120 },
  { title: 'Причина', key: 'parse_error_reason', sortable: false, width: 140 },
  { title: 'Повтор.', key: 'occurrences', sortable: false, width: 80 },
  { title: 'Статус', key: 'status', sortable: false, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 100 }
]

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function debouncedLoad() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => loadList(), 300)
}

async function loadList() {
  loading.value = true
  try {
    const params: any = {
      page: page.value,
      per_page: perPage.value
    }
    if (search.value.trim()) params.search = search.value.trim()
    if (materialTypeFilter.value) params.material_type = materialTypeFilter.value
    if (statusFilter.value) params.status = statusFilter.value
    if (sourceFilter.value.trim()) params.source = sourceFilter.value.trim()

    const response = await adminMaterialDimensionsApi.listFailures(params)
    items.value = response.data
    total.value = response.meta.total
  } catch (error) {
    console.error('Failed to load failures:', error)
  } finally {
    loading.value = false
  }
}

function materialTypeLabel(type: string | null): string {
  const opt = materialTypeOptions.find(o => o.value === type)
  return opt?.title || type || ''
}

function translateErrorReason(reason: string | null): string {
  if (!reason) return 'Неизвестно'
  const translations: Record<string, string> = {
    'no_match': 'Не распознано',
    'ambiguous': 'Неоднозначно',
    'invalid_format': 'Неверный формат',
    'missing_dimension': 'Нет размеров',
    'no_rule_match': 'Нет правила'
  }
  return translations[reason] || reason
}

function getRowProps({ item }: { item: MaterialDimensionParseFailure }) {
  return {
    class: selectedItem.value?.id === item.id ? 'selected-row' : '',
    style: 'cursor: pointer'
  }
}

function handleRowClick(_: Event, { item }: { item: MaterialDimensionParseFailure }) {
  selectItem(item)
}

function selectItem(item: MaterialDimensionParseFailure) {
  selectedItem.value = item
  // Pre-fill resolution with existing values
  resolution.value = {
    length: item.resolved_length_mm,
    width: item.resolved_width_mm,
    thickness: item.resolved_thickness_mm,
    note: item.resolution_note || ''
  }
  saveError.value = ''
  // Update URL
  router.replace({ query: { ...route.query, id: item.id } })
}

function closeInspector() {
  selectedItem.value = null
  const query = { ...route.query }
  delete query.id
  router.replace({ query })
}

function selectPrevious() {
  if (selectedIndex.value > 0) {
    const item = items.value[selectedIndex.value - 1]
    if (item) selectItem(item)
  }
}

function selectNext() {
  if (selectedIndex.value < items.value.length - 1) {
    const item = items.value[selectedIndex.value + 1]
    if (item) selectItem(item)
  }
}

async function saveResolution() {
  if (!selectedItem.value) return
  
  saving.value = true
  saveError.value = ''
  
  try {
    await adminMaterialDimensionsApi.updateFailure(selectedItem.value.id, {
      resolved_length_mm: resolution.value.length,
      resolved_width_mm: resolution.value.width,
      resolved_thickness_mm: resolution.value.thickness,
      resolution_note: resolution.value.note.trim() || null
    })
    
    // Move to next item
    if (selectedIndex.value < items.value.length - 1) {
      selectNext()
    } else {
      closeInspector()
    }
    
    // Reload list to update status
    loadList()
  } catch (error: any) {
    saveError.value = error?.response?.data?.message || 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

function skipAndNext() {
  if (selectedIndex.value < items.value.length - 1) {
    selectNext()
  } else {
    closeInspector()
  }
}

function openRuleCreator() {
  ruleCreatorOpen.value = true
}

function handleRuleSaved() {
  ruleCreatorOpen.value = false
  // Mark as resolved and move to next
  saveResolution()
}

// Open item from URL query
watch(() => route.query.id, (id) => {
  if (id && items.value.length > 0) {
    const item = items.value.find(i => i.id === Number(id))
    if (item) selectItem(item)
  }
}, { immediate: true })

onMounted(() => {
  loadList()
})
</script>

<style scoped>
.problems-view {
  display: flex;
  height: 100%;
  gap: 0;
}

.problems-main {
  flex: 1;
  min-width: 0;
  transition: all 0.3s ease;
}

.problems-main--with-panel {
  max-width: calc(100% - 420px);
}

.view-header {
  display: flex;
  align-items: flex-start;
  margin-bottom: 20px;
}

.raw-text-cell {
  max-width: 400px;
  font-family: monospace;
  font-size: 13px;
}

.problems-table :deep(.selected-row) {
  background: rgba(var(--v-theme-primary), 0.14) !important;
}

/* Inspector Panel */
.problems-inspector {
  width: 420px;
  min-width: 420px;
  background: rgb(var(--v-theme-surface));
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  display: flex;
  flex-direction: column;
  position: fixed;
  right: 0;
  top: 65px;
  height: calc(100vh - 65px);
  z-index: 10;
}

.inspector-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  flex-shrink: 0;
}

.inspector-content {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

.inspector-section {
  margin-bottom: 20px;
}

.section-label {
  font-size: 12px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.65);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
}

.mono-text {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-word;
}

.gap-1 {
  gap: 4px;
}

.gap-2 {
  gap: 8px;
}

/* Slide animation */
.slide-panel-enter-active,
.slide-panel-leave-active {
  transition: all 0.3s ease;
}

.slide-panel-enter-from,
.slide-panel-leave-to {
  transform: translateX(100%);
}

@media (max-width: 1200px) {
  .problems-main--with-panel {
    max-width: 100%;
  }
  
  .problems-inspector {
    box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
  }
}
</style>
