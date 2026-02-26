<template>
  <v-container>
    <v-card>
      <v-card-title>Технологические операции</v-card-title>
      <v-card-actions>
        <v-btn prepend-icon="mdi-plus" @click="openCreateDialog">Новая операция</v-btn>
        <v-btn prepend-icon="mdi-file-import" color="primary" variant="tonal" @click="showImportDialog = true">
          Импорт прайса
        </v-btn>
      </v-card-actions>

      <v-data-table
        :headers="headers"
        :items="operations"
        :loading="loading"
        density="compact"
        class="operations-compact-table"
      >
        <template #item.linked_prices_count="{ item }">
          <div class="d-flex align-center ga-2">
            <v-chip
              size="small"
              :color="(item.linked_prices_count || 0) > 0 ? 'success' : 'default'"
              variant="tonal"
            >
              {{ item.linked_prices_count || 0 }}
            </v-chip>
            <v-btn
              size="small"
              variant="text"
              color="primary"
              :disabled="(item.linked_prices_count || 0) === 0"
              @click="openLinksDialog(item)"
            >
              Управлять
            </v-btn>
          </div>
        </template>

        <template #item.actions="{ item }">
          <div class="actions-cell">
            <v-btn v-if="item.origin === 'user'" variant="text" icon size="small" @click="editOperation(item)">
              <v-icon size="18">mdi-pencil</v-icon>
            </v-btn>
            <v-btn v-if="item.origin === 'user'" variant="text" icon size="small" @click="deleteOperation(item)">
              <v-icon size="18">mdi-delete</v-icon>
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </v-card>

    <PriceImportDialog
      v-model="showImportDialog"
      target-type="operations"
      @imported="fetchOperations"
    />

    <v-dialog v-model="dialog" max-width="600">
      <v-card>
        <v-card-title>{{ editing ? 'Редактировать операцию' : 'Новая операция' }}</v-card-title>
        <v-card-text>
          <v-form v-model="valid">
            <v-text-field v-model="form.name" label="Наименование" required />
            <v-combobox
              v-model="form.category"
              :items="availableCategories"
              label="Категория"
              required
              @focus="loadCategories"
              clearable
            />
            <v-combobox
              v-model="form.exclusion_group"
              :items="availableExclusionGroups"
              label="Группа исключений"
              @focus="loadExclusionGroups"
              clearable
            />
            <v-text-field v-model="form.min_thickness" label="Мин. толщина" type="number" step="1" clearable />
            <v-text-field v-model="form.max_thickness" label="Макс. толщина" type="number" step="1" clearable />
            <v-combobox
              v-model="form.unit"
              :items="availableUnits"
              label="Единица измерения"
              required
              @focus="loadUnits"
              clearable
            />
            <v-textarea v-model="form.description" label="Описание" />
          </v-form>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="isSaving" @click="saveOperation">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="linksDialog.show" max-width="1100">
      <v-card>
        <v-card-title>
          Связи из прайса: {{ linksDialog.operation?.name }}
        </v-card-title>
        <v-card-text>
          <v-data-table
            :headers="linkHeaders"
            :items="linksDialog.rows"
            :loading="linksDialog.loading"
            item-key="id"
            density="comfortable"
          >
            <template #item.prices="{ item }">
              <div>
                <div>{{ formatMoney(item.price_per_internal_unit) }}</div>
                <div class="text-caption text-medium-emphasis">Источник: {{ formatMoney(item.source_price) }}</div>
              </div>
            </template>
            <template #item.version="{ item }">
              <div>{{ item.price_list_name || '—' }}</div>
              <div class="text-caption text-medium-emphasis">
                v{{ item.version_number || '—' }} · {{ item.supplier_name || '—' }}
              </div>
            </template>
            <template #item.actions="{ item }">
              <div class="d-flex ga-1">
                <v-btn size="small" variant="tonal" color="primary" @click="openRebindDialog(item)">
                  Перепривязать
                </v-btn>
                <v-btn size="small" variant="text" color="primary" @click="openCreateAndBindDialog(item)">
                  Создать и привязать
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="linksDialog.show = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="rebindDialog.show" max-width="650">
      <v-card>
        <v-card-title>Перепривязка операции</v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-3">
            Строка прайса: <strong>{{ rebindDialog.row?.source_name }}</strong>
          </div>
          <v-autocomplete
            v-model="rebindDialog.selectedOperationId"
            :items="rebindDialog.operationResults"
            :loading="rebindDialog.searching"
            item-title="name"
            item-value="id"
            label="Базовая операция"
            placeholder="Введите название..."
            no-data-text="Ничего не найдено"
            clearable
            @update:search="searchOperationsForRebind"
          >
            <template #item="{ item, props }">
              <v-list-item v-bind="props">
                <template #subtitle>
                  {{ item.raw.category }} · {{ item.raw.unit }}
                </template>
              </v-list-item>
            </template>
          </v-autocomplete>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="rebindDialog.show = false">Отмена</v-btn>
          <v-btn
            color="primary"
            :loading="rebindDialog.saving"
            :disabled="!rebindDialog.selectedOperationId"
            @click="confirmRebind"
          >
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="createAndBindDialog.show" max-width="680">
      <v-card>
        <v-card-title>Новая базовая операция и привязка</v-card-title>
        <v-card-text>
          <v-text-field v-model="createAndBindDialog.form.name" label="Наименование" required />
          <v-combobox
            v-model="createAndBindDialog.form.category"
            :items="availableCategories"
            label="Категория"
            @focus="loadCategories"
          />
          <v-combobox
            v-model="createAndBindDialog.form.unit"
            :items="availableUnits"
            label="Единица измерения"
            @focus="loadUnits"
          />
          <v-textarea v-model="createAndBindDialog.form.description" label="Описание" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="createAndBindDialog.show = false">Отмена</v-btn>
          <v-btn color="primary" :loading="createAndBindDialog.saving" @click="confirmCreateAndBind">
            Создать и привязать
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import api from '@/api/axios'
import PriceImportDialog from '@/components/PriceImportDialog.vue'

interface OperationRow {
  id: number
  name: string
  category?: string
  exclusion_group?: string
  min_thickness?: number | null
  max_thickness?: number | null
  unit?: string
  description?: string | null
  origin?: string | null
  linked_prices_count?: number
}

interface LinkedPriceRow {
  id: number
  source_name: string
  source_unit?: string | null
  source_price?: number | null
  price_per_internal_unit?: number | null
  currency?: string | null
  supplier_name?: string | null
  price_list_name?: string | null
  version_number?: number | null
}

interface OperationSearchResult {
  id: number
  name: string
  unit: string
  category: string
}

const operations = ref<OperationRow[]>([])
const loading = ref(false)
const dialog = ref(false)
const editing = ref(false)
const valid = ref(false)
const showImportDialog = ref(false)
const isSaving = ref(false)

const form = ref({
  id: null as number | null,
  name: '',
  category: '',
  exclusion_group: '',
  min_thickness: null as number | null,
  max_thickness: null as number | null,
  unit: '',
  description: '',
})

const headers = [
  { title: 'Наименование', key: 'name' },
  { title: 'Категория', key: 'category' },
  { title: 'Группа исключений', key: 'exclusion_group' },
  { title: 'Мин. толщина', key: 'min_thickness' },
  { title: 'Макс. толщина', key: 'max_thickness' },
  { title: 'Ед. изм.', key: 'unit' },
  { title: 'Привязки из прайса', key: 'linked_prices_count', sortable: true, width: '170px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '92px' },
]

const linkHeaders = [
  { title: 'Операция из прайса', key: 'source_name' },
  { title: 'Ед.', key: 'source_unit', width: '90px' },
  { title: 'Цены', key: 'prices', sortable: false, width: '160px' },
  { title: 'Прайс/поставщик', key: 'version', sortable: false, width: '260px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '240px' },
]

const linksDialog = ref({
  show: false,
  loading: false,
  operation: null as OperationRow | null,
  rows: [] as LinkedPriceRow[],
})

const rebindDialog = ref({
  show: false,
  row: null as LinkedPriceRow | null,
  selectedOperationId: null as number | null,
  operationResults: [] as OperationSearchResult[],
  searching: false,
  saving: false,
})

const createAndBindDialog = ref({
  show: false,
  row: null as LinkedPriceRow | null,
  saving: false,
  form: {
    name: '',
    category: 'Импорт',
    unit: '',
    description: '',
  },
})

const availableCategories = ref<string[]>([])
const availableUnits = ref<string[]>([])
const availableExclusionGroups = ref<string[]>([])

const fetchOperations = async () => {
  loading.value = true
  try {
    operations.value = (await api.get('/api/operations')).data
  } finally {
    loading.value = false
  }
}

const openCreateDialog = () => {
  editing.value = false
  form.value = {
    id: null,
    name: '',
    category: '',
    exclusion_group: '',
    min_thickness: null,
    max_thickness: null,
    unit: '',
    description: '',
  }
  dialog.value = true
}

const editOperation = (item: OperationRow) => {
  editing.value = true
  form.value = {
    id: item.id,
    name: item.name || '',
    category: item.category || '',
    exclusion_group: item.exclusion_group || '',
    min_thickness: item.min_thickness ?? null,
    max_thickness: item.max_thickness ?? null,
    unit: item.unit || '',
    description: item.description || '',
  }
  dialog.value = true
}

const saveOperation = async () => {
  if (isSaving.value) return
  isSaving.value = true
  try {
    if (editing.value && form.value.id) {
      await api.put(`/api/operations/${form.value.id}`, form.value)
    } else {
      await api.post('/api/operations', form.value)
    }
    dialog.value = false
    await fetchOperations()
  } catch (error) {
    console.error('Ошибка сохранения:', error)
  } finally {
    isSaving.value = false
  }
}

const deleteOperation = async (item: OperationRow) => {
  if (!confirm('Удалить операцию?')) return
  await api.delete(`/api/operations/${item.id}`)
  await fetchOperations()
}

const loadCategories = async () => {
  if (availableCategories.value.length > 0) return
  const response = await api.get('/api/operations/categories')
  availableCategories.value = response.data
}

const loadUnits = async () => {
  if (availableUnits.value.length > 0) return
  const response = await api.get('/api/units')
  availableUnits.value = response.data
}

const loadExclusionGroups = async () => {
  if (availableExclusionGroups.value.length > 0) return
  const response = await api.get('/api/operations/exclusion-groups')
  availableExclusionGroups.value = response.data
}

const openLinksDialog = async (item: OperationRow) => {
  linksDialog.value.show = true
  linksDialog.value.operation = item
  await loadOperationLinks(item.id)
}

const loadOperationLinks = async (operationId: number) => {
  linksDialog.value.loading = true
  try {
    const { data } = await api.get(`/api/operations/${operationId}/price-links`, {
      params: { limit: 500 },
    })
    linksDialog.value.rows = data?.data || []
  } catch (error) {
    console.error('Не удалось загрузить связи:', error)
    linksDialog.value.rows = []
  } finally {
    linksDialog.value.loading = false
  }
}

let rebindSearchDebounce: ReturnType<typeof setTimeout> | null = null

const searchOperationsForRebind = (query: string | null) => {
  if (rebindSearchDebounce) clearTimeout(rebindSearchDebounce)
  if (!query || query.length < 2) return
  rebindSearchDebounce = setTimeout(async () => {
    rebindDialog.value.searching = true
    try {
      const { data } = await api.get('/api/operations/search', {
        params: { q: query, limit: 100 },
      })
      rebindDialog.value.operationResults = data
    } finally {
      rebindDialog.value.searching = false
    }
  }, 250)
}

const openRebindDialog = (row: LinkedPriceRow) => {
  rebindDialog.value.show = true
  rebindDialog.value.row = row
  rebindDialog.value.selectedOperationId = null
  rebindDialog.value.operationResults = []
  if (row.source_name) {
    searchOperationsForRebind(row.source_name)
  }
}

const confirmRebind = async () => {
  if (!rebindDialog.value.row?.id || !rebindDialog.value.selectedOperationId) return
  rebindDialog.value.saving = true
  try {
    await api.put(`/api/operation-prices/${rebindDialog.value.row.id}/link`, {
      operation_id: rebindDialog.value.selectedOperationId,
      force_replace: true,
    })
    rebindDialog.value.show = false
    if (linksDialog.value.operation) {
      await loadOperationLinks(linksDialog.value.operation.id)
    }
    await fetchOperations()
  } catch (error) {
    console.error('Ошибка перепривязки:', error)
  } finally {
    rebindDialog.value.saving = false
  }
}

const openCreateAndBindDialog = async (row: LinkedPriceRow) => {
  await loadCategories()
  await loadUnits()
  createAndBindDialog.value.show = true
  createAndBindDialog.value.row = row
  createAndBindDialog.value.form = {
    name: row.source_name || '',
    category: linksDialog.value.operation?.category || 'Импорт',
    unit: row.source_unit || linksDialog.value.operation?.unit || '',
    description: '',
  }
}

const confirmCreateAndBind = async () => {
  const row = createAndBindDialog.value.row
  if (!row?.id) return

  createAndBindDialog.value.saving = true
  try {
    const createPayload = {
      name: createAndBindDialog.value.form.name,
      category: createAndBindDialog.value.form.category || 'Импорт',
      unit: createAndBindDialog.value.form.unit || 'шт',
      description: createAndBindDialog.value.form.description || null,
      exclusion_group: null,
      min_thickness: null,
      max_thickness: null,
    }
    const created = await api.post('/api/operations', createPayload)
    const newOperationId = created?.data?.id
    if (!newOperationId) {
      throw new Error('Не удалось создать базовую операцию')
    }

    await api.put(`/api/operation-prices/${row.id}/link`, {
      operation_id: newOperationId,
      force_replace: true,
    })

    createAndBindDialog.value.show = false
    if (linksDialog.value.operation) {
      await loadOperationLinks(linksDialog.value.operation.id)
    }
    await fetchOperations()
  } catch (error) {
    console.error('Ошибка создания и привязки:', error)
  } finally {
    createAndBindDialog.value.saving = false
  }
}

const formatMoney = (value: number | string | null | undefined) => {
  if (value === null || value === undefined) return '—'
  const number = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(number)) return '—'
  return number.toFixed(2)
}

fetchOperations()
</script>

<style scoped>
:deep(.operations-compact-table .v-data-table__td),
:deep(.operations-compact-table .v-data-table__th) {
  padding-top: 6px !important;
  padding-bottom: 6px !important;
  font-size: 12.5px;
}

:deep(.operations-compact-table .v-btn) {
  min-height: 28px;
}

.actions-cell {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 2px;
  white-space: nowrap;
  flex-wrap: nowrap;
}
</style>
