<template>
  <PageContainer>
    <PageHeader
      title="Правила распознавания"
      subtitle="Управление правилами парсинга размеров и типов материалов"
    >
      <template #actions>
        <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">
          Новое правило
        </v-btn>
      </template>
    </PageHeader>

    <!-- Rule type toggle -->
    <v-btn-toggle
      v-model="ruleType"
      mandatory
      color="primary"
      class="mb-4"
    >
      <v-btn value="dimensions" prepend-icon="mdi-ruler">
        Размеры
        <v-chip v-if="dimensionStats.total" size="x-small" class="ml-2" color="primary" variant="tonal">
          {{ dimensionStats.total }}
        </v-chip>
      </v-btn>
      <v-btn value="types" prepend-icon="mdi-shape">
        Тип материала
        <v-chip v-if="typeStats.total" size="x-small" class="ml-2" color="primary" variant="tonal">
          {{ typeStats.total }}
        </v-chip>
      </v-btn>
    </v-btn-toggle>

    <!-- Dimension Rules Section -->
    <template v-if="ruleType === 'dimensions'">
      <v-card variant="outlined" class="mb-4">
        <v-card-text>
          <v-row align="center" dense>
            <v-col cols="12" sm="4">
              <v-text-field
                v-model="dimSearch"
                prepend-inner-icon="mdi-magnify"
                label="Поиск правил"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="debouncedLoadDimensions"
              />
            </v-col>
            <v-col cols="6" sm="3">
              <v-select
                v-model="dimMaterialType"
                :items="materialTypeOptions"
                label="Тип материала"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="loadDimensionRules"
              />
            </v-col>
            <v-col cols="6" sm="3">
              <v-select
                v-model="dimStatus"
                :items="statusOptions"
                label="Статус"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="loadDimensionRules"
              />
            </v-col>
            <v-col cols="12" sm="2" class="text-right">
              <v-btn variant="tonal" prepend-icon="mdi-refresh" @click="loadDimensionRules">
                Обновить
              </v-btn>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>

      <v-card variant="outlined" :loading="dimLoading">
        <v-data-table-server
          :headers="dimensionHeaders"
          :items="dimensionRules"
          :items-length="dimensionTotal"
          :loading="dimLoading"
          :page="dimPage"
          :items-per-page="dimPerPage"
          density="comfortable"
          @update:page="dimPage = $event; loadDimensionRules()"
          @update:items-per-page="dimPerPage = $event; loadDimensionRules()"
        >
          <template #item.name="{ item }">
            <div>
              <div class="font-weight-medium">{{ item.name }}</div>
              <div v-if="item.description" class="text-caption text-medium-emphasis text-truncate" style="max-width: 300px;">
                {{ item.description }}
              </div>
            </div>
          </template>

          <template #item.scope="{ item }">
            <div class="text-body-2">
              <v-chip v-if="item.material_type" size="small" variant="tonal">
                {{ materialTypeLabel(item.material_type) }}
              </v-chip>
              <span v-else class="text-medium-emphasis">Любой</span>
              <div class="text-caption text-medium-emphasis">{{ item.source || 'Любой источник' }}</div>
            </div>
          </template>

          <template #item.dimensions="{ item }">
            <div class="text-caption">
              <span v-if="item.expected_result?.length_mm">Д: {{ item.expected_result.length_mm }}</span>
              <span v-if="item.expected_result?.width_mm" class="ml-2">Ш: {{ item.expected_result.width_mm }}</span>
              <span v-if="item.expected_result?.thickness_mm" class="ml-2">Т: {{ item.expected_result.thickness_mm }}</span>
            </div>
          </template>

          <template #item.is_active="{ item }">
            <v-chip :color="item.is_active ? 'success' : 'grey'" size="small" variant="tonal">
              {{ item.is_active ? 'Активно' : 'Выключено' }}
            </v-chip>
          </template>

          <template #item.updated_at="{ item }">
            <span class="text-caption text-medium-emphasis">{{ item.updated_at ? formatDate(item.updated_at) : '—' }}</span>
          </template>

          <template #item.actions="{ item }">
            <div class="d-flex gap-1">
              <v-btn size="x-small" variant="text" icon="mdi-pencil" @click="openEditDimension(item)" />
              <v-btn size="x-small" variant="text" icon="mdi-delete" color="error" @click="deleteDimensionRule(item)" />
            </div>
          </template>

          <template #no-data>
            <div class="text-center py-8 text-medium-emphasis">
              <v-icon icon="mdi-ruler" size="48" color="grey" class="mb-3" />
              <div class="text-body-1 mb-1">Нет правил размеров</div>
              <div class="text-body-2">Добавьте первое правило для парсинга размеров</div>
            </div>
          </template>
        </v-data-table-server>
      </v-card>
    </template>

    <!-- Type Patterns Section -->
    <template v-if="ruleType === 'types'">
      <v-card variant="outlined" class="mb-4">
        <v-card-text>
          <v-row align="center" dense>
            <v-col cols="12" sm="4">
              <v-text-field
                v-model="typeSearch"
                prepend-inner-icon="mdi-magnify"
                label="Поиск паттернов"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="debouncedLoadTypes"
              />
            </v-col>
            <v-col cols="6" sm="3">
              <v-select
                v-model="typeMaterialType"
                :items="materialTypeOptions"
                label="Тип материала"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="loadTypePatterns"
              />
            </v-col>
            <v-col cols="6" sm="3">
              <v-select
                v-model="typeStatus"
                :items="statusOptions"
                label="Статус"
                hide-details
                variant="outlined"
                density="compact"
                clearable
                @update:model-value="loadTypePatterns"
              />
            </v-col>
            <v-col cols="12" sm="2" class="text-right">
              <v-btn variant="tonal" prepend-icon="mdi-refresh" @click="loadTypePatterns">
                Обновить
              </v-btn>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>

      <v-card variant="outlined" :loading="typeLoading">
        <v-data-table-server
          :headers="typeHeaders"
          :items="typePatterns"
          :items-length="typeTotal"
          :loading="typeLoading"
          :page="typePage"
          :items-per-page="typePerPage"
          density="comfortable"
          @update:page="typePage = $event; loadTypePatterns()"
          @update:items-per-page="typePerPage = $event; loadTypePatterns()"
        >
          <template #item.pattern="{ item }">
            <div>
              <code class="text-body-2">{{ truncatePattern(item.pattern) }}</code>
            </div>
          </template>

          <template #item.material_type="{ item }">
            <v-chip :color="typeColor(item.material_type)" size="small" variant="tonal">
              {{ materialTypeLabel(item.material_type) }}
            </v-chip>
          </template>

          <template #item.priority="{ item }">
            <span class="text-caption">{{ item.priority }}</span>
          </template>

          <template #item.is_active="{ item }">
            <v-chip :color="item.is_active ? 'success' : 'grey'" size="small" variant="tonal">
              {{ item.is_active ? 'Активно' : 'Выключено' }}
            </v-chip>
          </template>

          <template #item.actions="{ item }">
            <div class="d-flex gap-1">
              <v-btn size="x-small" variant="text" icon="mdi-pencil" @click="openEditType(item)" />
              <v-btn size="x-small" variant="text" icon="mdi-delete" color="error" @click="deleteTypePattern(item)" />
            </div>
          </template>

          <template #no-data>
            <div class="text-center py-8 text-medium-emphasis">
              <v-icon icon="mdi-shape" size="48" color="grey" class="mb-3" />
              <div class="text-body-1 mb-1">Нет паттернов типов</div>
              <div class="text-body-2">Добавьте первый паттерн для определения типа материала</div>
            </div>
          </template>
        </v-data-table-server>
      </v-card>
    </template>

    <!-- Edit Dimension Rule Dialog -->
    <v-dialog v-model="dimDialog" max-width="900" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          {{ editingDimId ? 'Редактировать правило' : 'Новое правило размеров' }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="dimDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <AdminMaterialDimensionRulesTab
            v-if="dimDialog"
            :initial-rule-id="editingDimId"
            embedded
            @saved="handleDimSaved"
            @cancel="dimDialog = false"
          />
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Edit Type Pattern Dialog -->
    <v-dialog v-model="typeDialog" max-width="700" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          {{ editingTypeId ? 'Редактировать паттерн' : 'Новый паттерн типа' }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="typeDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-form @submit.prevent="saveTypePattern">
            <v-alert v-if="typeFormError" type="error" variant="tonal" class="mb-4" closable @click:close="typeFormError = ''">
              {{ typeFormError }}
            </v-alert>
            
            <v-row>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="typeForm.name"
                  label="Название *"
                  variant="outlined"
                  density="compact"
                  placeholder="plate_ldsp_pattern"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="typeForm.material_type"
                  :items="materialTypeOptions"
                  label="Тип материала *"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
              <v-col cols="12" md="8">
                <v-text-field
                  v-model="typeForm.pattern"
                  label="Regex паттерн *"
                  variant="outlined"
                  density="compact"
                  placeholder="(?:ЛДСП|КДСП)"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-select
                  v-model="typeForm.target_field"
                  :items="[{ title: 'Название', value: 'title' }, { title: 'URL', value: 'url' }, { title: 'Оба', value: 'title_or_url' }]"
                  label="Поле для поиска"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
              <v-col cols="12" md="8">
                <v-textarea
                  v-model="typeForm.description"
                  label="Описание"
                  variant="outlined"
                  density="compact"
                  rows="2"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model.number="typeForm.priority"
                  label="Приоритет"
                  type="number"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
              <v-col cols="12">
                <v-switch
                  v-model="typeForm.is_active"
                  label="Паттерн активен"
                  color="success"
                  hide-details
                />
              </v-col>
            </v-row>
            
            <div class="d-flex gap-2 mt-4">
              <v-btn type="submit" color="primary" :loading="typeSaving">
                Сохранить
              </v-btn>
              <v-btn variant="text" @click="typeDialog = false">
                Отмена
              </v-btn>
            </div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Confirm Delete Dialog -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card>
        <v-card-title>Удалить правило?</v-card-title>
        <v-card-text>
          Это действие нельзя отменить. Правило будет удалено навсегда.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="deleting" @click="confirmDelete">
            Удалить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import {
  adminMaterialDimensionsApi,
  type MaterialDimensionRule,
  type MaterialTypePattern,
  type MaterialDimensionMaterialType
} from '@/api/materialDimensions'
import AdminMaterialDimensionRulesTab from '@/components/admin/AdminMaterialDimensionRulesTab.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

// Toggle between dimension rules and type patterns
const ruleType = ref<'dimensions' | 'types'>('dimensions')

// Stats
const dimensionStats = reactive({ total: 0, active: 0 })
const typeStats = reactive({ total: 0, active: 0 })

// Dimension rules state
const dimLoading = ref(false)
const dimensionRules = ref<MaterialDimensionRule[]>([])
const dimensionTotal = ref(0)
const dimPage = ref(1)
const dimPerPage = ref(25)
const dimSearch = ref('')
const dimMaterialType = ref<MaterialDimensionMaterialType | null>(null)
const dimStatus = ref<'active' | 'inactive' | null>(null)
const dimDialog = ref(false)
const editingDimId = ref<number | null>(null)

// Type patterns state
const typeLoading = ref(false)
const typePatterns = ref<MaterialTypePattern[]>([])
const typeTotal = ref(0)
const typePage = ref(1)
const typePerPage = ref(25)
const typeSearch = ref('')
const typeMaterialType = ref<MaterialDimensionMaterialType | null>(null)
const typeStatus = ref<'active' | 'inactive' | null>(null)
const typeDialog = ref(false)
const editingTypeId = ref<number | null>(null)
const typeFormError = ref('')
const typeSaving = ref(false)

const typeForm = ref({
  name: '',
  pattern: '',
  material_type: null as MaterialDimensionMaterialType | null,
  description: '',
  priority: 100,
  is_active: true,
  target_field: 'title' as 'title' | 'url' | 'title_or_url'
})

// Delete state
const deleteDialog = ref(false)
const deleteTarget = ref<{ type: 'dimension' | 'type'; id: number } | null>(null)
const deleting = ref(false)

const materialTypeOptions = [
  { title: 'Плита', value: 'plate' },
  { title: 'Кромка', value: 'edge' },
  { title: 'Фурнитура', value: 'hardware' },
  { title: 'Фасад', value: 'facade' },
  { title: 'Комплектующие', value: 'fitting' }
]

const statusOptions = [
  { title: 'Активные', value: 'active' },
  { title: 'Выключенные', value: 'inactive' }
]

const dimensionHeaders = [
  { title: 'Правило', key: 'name', sortable: false },
  { title: 'Область', key: 'scope', sortable: false, width: 150 },
  { title: 'Размеры', key: 'dimensions', sortable: false, width: 180 },
  { title: 'Статус', key: 'is_active', sortable: false, width: 100 },
  { title: 'Обновлено', key: 'updated_at', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 120 }
]

const typeHeaders = [
  { title: 'Паттерн', key: 'pattern', sortable: false },
  { title: 'Тип материала', key: 'material_type', sortable: false, width: 150 },
  { title: 'Приоритет', key: 'priority', sortable: false, width: 100 },
  { title: 'Статус', key: 'is_active', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 120 }
]

let dimSearchTimeout: ReturnType<typeof setTimeout> | null = null
let typeSearchTimeout: ReturnType<typeof setTimeout> | null = null

function debouncedLoadDimensions() {
  if (dimSearchTimeout) clearTimeout(dimSearchTimeout)
  dimSearchTimeout = setTimeout(() => loadDimensionRules(), 300)
}

function debouncedLoadTypes() {
  if (typeSearchTimeout) clearTimeout(typeSearchTimeout)
  typeSearchTimeout = setTimeout(() => loadTypePatterns(), 300)
}

async function loadDimensionRules() {
  dimLoading.value = true
  try {
    const params: any = {
      page: dimPage.value,
      per_page: dimPerPage.value
    }
    if (dimSearch.value.trim()) params.search = dimSearch.value.trim()
    if (dimMaterialType.value) params.material_type = dimMaterialType.value
    if (dimStatus.value) params.status = dimStatus.value

    const response = await adminMaterialDimensionsApi.listRules(params)
    dimensionRules.value = response.data
    dimensionTotal.value = response.meta.total
    dimensionStats.total = response.meta.total
  } catch (error) {
    console.error('Failed to load dimension rules:', error)
  } finally {
    dimLoading.value = false
  }
}

async function loadTypePatterns() {
  typeLoading.value = true
  try {
    const params: any = {
      page: typePage.value,
      per_page: typePerPage.value
    }
    if (typeSearch.value.trim()) params.search = typeSearch.value.trim()
    if (typeMaterialType.value) params.material_type = typeMaterialType.value
    if (typeStatus.value) params.status = typeStatus.value

    const response = await adminMaterialDimensionsApi.listTypePatterns(params)
    typePatterns.value = response.data
    typeTotal.value = response.meta.total
    typeStats.total = response.meta.total
  } catch (error) {
    console.error('Failed to load type patterns:', error)
  } finally {
    typeLoading.value = false
  }
}

function materialTypeLabel(type: string | null): string {
  const opt = materialTypeOptions.find(o => o.value === type)
  return opt?.title || type || '—'
}

function typeColor(type: string): string {
  const colors: Record<string, string> = {
    plate: 'blue',
    edge: 'orange',
    hardware: 'purple',
    facade: 'green',
    fitting: 'cyan'
  }
  return colors[type] || 'grey'
}

function formatDate(date: string): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function truncatePattern(pattern: string): string {
  return pattern.length > 60 ? pattern.slice(0, 60) + '...' : pattern
}

function openCreateDialog() {
  if (ruleType.value === 'dimensions') {
    editingDimId.value = null
    dimDialog.value = true
  } else {
    editingTypeId.value = null
    const timestamp = Date.now().toString(36)
    typeForm.value = {
      name: `type_pattern_${timestamp}`,
      pattern: '',
      material_type: null,
      description: '',
      priority: 100,
      is_active: true,
      target_field: 'title'
    }
    typeFormError.value = ''
    typeDialog.value = true
  }
}

function openEditDimension(item: MaterialDimensionRule) {
  editingDimId.value = item.id
  dimDialog.value = true
}

function openEditType(item: MaterialTypePattern) {
  editingTypeId.value = item.id
  typeForm.value = {
    name: item.name,
    pattern: item.pattern,
    material_type: item.material_type,
    description: item.description || '',
    priority: item.priority,
    is_active: item.is_active,
    target_field: item.target_field
  }
  typeFormError.value = ''
  typeDialog.value = true
}

function deleteDimensionRule(item: MaterialDimensionRule) {
  deleteTarget.value = { type: 'dimension', id: item.id }
  deleteDialog.value = true
}

function deleteTypePattern(item: MaterialTypePattern) {
  deleteTarget.value = { type: 'type', id: item.id }
  deleteDialog.value = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  
  deleting.value = true
  try {
    if (deleteTarget.value.type === 'dimension') {
      await adminMaterialDimensionsApi.deleteRule(deleteTarget.value.id)
      loadDimensionRules()
    } else {
      await adminMaterialDimensionsApi.deleteTypePattern(deleteTarget.value.id)
      loadTypePatterns()
    }
    deleteDialog.value = false
    deleteTarget.value = null
  } catch (error) {
    console.error('Failed to delete:', error)
  } finally {
    deleting.value = false
  }
}

async function saveTypePattern() {
  if (!typeForm.value.name.trim() || !typeForm.value.pattern.trim() || !typeForm.value.material_type) {
    typeFormError.value = 'Заполните обязательные поля'
    return
  }
  
  typeSaving.value = true
  typeFormError.value = ''
  
  try {
    const payload = {
      name: typeForm.value.name.trim(),
      pattern: typeForm.value.pattern.trim(),
      material_type: typeForm.value.material_type,
      description: typeForm.value.description.trim() || null,
      priority: typeForm.value.priority,
      is_active: typeForm.value.is_active,
      rule_type: 'regex' as const,
      target_field: typeForm.value.target_field
    }
    
    if (editingTypeId.value) {
      await adminMaterialDimensionsApi.updateTypePattern(editingTypeId.value, payload)
    } else {
      await adminMaterialDimensionsApi.createTypePattern(payload)
    }
    
    typeDialog.value = false
    loadTypePatterns()
  } catch (error: any) {
    typeFormError.value = error?.response?.data?.message || 'Не удалось сохранить'
  } finally {
    typeSaving.value = false
  }
}

function handleDimSaved() {
  dimDialog.value = false
  loadDimensionRules()
}

onMounted(() => {
  loadDimensionRules()
  loadTypePatterns()
})
</script>

<style scoped>
.gap-1 {
  gap: 4px;
}

.gap-2 {
  gap: 8px;
}

code {
  background: rgba(var(--v-theme-on-surface), 0.08);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
