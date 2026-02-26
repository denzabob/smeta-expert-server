<template>
  <v-container fluid class="soft-page detail-types-view">
    <v-card class="soft-content-card soft-data-card" elevation="0" variant="outlined">
      <v-card-title class="d-flex align-center justify-space-between flex-wrap ga-2">
        <div>
          <div class="text-h5">Типы деталей</div>
          <div class="text-caption text-medium-emphasis mt-1">
            Шаги: 1) Создайте тип 2) Выберите схему торцов 3) При необходимости привяжите операции
          </div>
        </div>
        <div class="d-flex align-center ga-2 flex-wrap">
          <v-menu>
            <template #activator="{ props }">
              <v-btn variant="outlined" prepend-icon="mdi-shape-plus" v-bind="props">
                Из шаблона
              </v-btn>
            </template>
            <v-list density="compact">
              <v-list-item
                v-for="tpl in quickTemplates"
                :key="tpl.name"
                @click="openCreateDialog(tpl)"
              >
                <v-list-item-title>{{ tpl.name }}</v-list-item-title>
                <v-list-item-subtitle>{{ getEdgeLabel(tpl.edge_processing) }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-menu>

          <v-btn
            color="primary"
            prepend-icon="mdi-plus"
            variant="elevated"
            @click="openCreateDialog()"
          >
            Новый тип
          </v-btn>
        </div>
      </v-card-title>

      <v-card-text class="pb-2">
        <v-row dense>
          <v-col cols="12" md="5">
            <v-text-field
              v-model="searchQuery"
              label="Поиск по названию"
              prepend-inner-icon="mdi-magnify"
              clearable
              variant="outlined"
              density="compact"
              hide-details
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="edgeFilter"
              :items="edgeFilterOptions"
              item-title="title"
              item-value="value"
              label="Схема торцов"
              variant="outlined"
              density="compact"
              hide-details
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-select
              v-model="usageFilter"
              :items="usageFilterOptions"
              item-title="title"
              item-value="value"
              label="Использование"
              variant="outlined"
              density="compact"
              hide-details
            />
          </v-col>
        </v-row>

        <v-alert
          v-if="selectedDetailTypeIds.length > 0"
          density="compact"
          variant="tonal"
          type="info"
          class="mt-3"
        >
          Выбрано: {{ selectedDetailTypeIds.length }}
          <template #append>
            <div class="d-flex align-center ga-2 flex-wrap">
              <v-select
                v-model="bulkAction"
                :items="bulkActionOptions"
                item-title="title"
                item-value="value"
                label="Действие"
                density="compact"
                variant="outlined"
                hide-details
                style="min-width: 170px"
              />
              <v-select
                v-if="bulkAction === 'set_edge'"
                v-model="bulkEdgeScheme"
                :items="edgeOptions"
                item-title="title"
                item-value="value"
                label="Схема"
                density="compact"
                variant="outlined"
                hide-details
                style="min-width: 220px"
              />
              <v-btn
                color="primary"
                variant="flat"
                size="small"
                :disabled="!bulkActionReady"
                @click="applyBulkAction"
              >
                Применить
              </v-btn>
            </div>
          </template>
        </v-alert>
      </v-card-text>

      <v-data-table
        :headers="headers"
        :items="filteredDetailTypes"
        :loading="loading"
        density="comfortable"
        class="soft-data-table"
        :no-data-text="loading ? '' : 'Нет типов деталей'"
        item-value="id"
        show-select
        v-model="selectedDetailTypeIds"
      >
        <template #item.name="{ item }">
          <div class="font-weight-medium">{{ item.name }}</div>
        </template>

        <template #item.edge_processing="{ item }">
          <v-chip size="small" color="primary" variant="flat">
            <v-icon start size="14">{{ getEdgeIcon(item.edge_processing) }}</v-icon>
            {{ getEdgeLabel(item.edge_processing) }}
          </v-chip>
        </template>

        <template #item.positions_count="{ item }">
          <v-chip
            size="small"
            :color="Number(item.positions_count || 0) > 0 ? 'primary' : 'grey'"
            variant="tonal"
          >
            {{ item.positions_count || 0 }}
          </v-chip>
        </template>

        <template #item.components="{ item }">
          <span class="text-caption text-medium-emphasis">
            {{ item.components?.length || 0 }} операций
          </span>
        </template>

        <template #item.origin="{ item }">
          <v-chip
            size="small"
            :color="item.origin === 'system' ? 'grey' : 'success'"
            variant="tonal"
          >
            {{ item.origin === 'system' ? 'Системный' : 'Пользовательский' }}
          </v-chip>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-2 justify-end">
            <v-btn
              icon="mdi-pencil"
              size="small"
              variant="text"
              :disabled="!isEditable(item)"
              :title="isEditable(item) ? 'Редактировать' : 'Системный тип не редактируется'"
              @click="edit(item)"
            />
            <v-btn
              icon="mdi-delete"
              size="small"
              variant="text"
              color="error"
              :disabled="!isEditable(item)"
              :title="isEditable(item) ? 'Удалить' : 'Системный тип не удаляется'"
              @click="remove(item)"
            />
          </div>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="760" persistent>
      <v-card class="soft-content-card soft-dialog-card">
        <v-card-title class="text-h6 d-flex align-center justify-space-between">
          <span>{{ editing ? 'Редактировать тип детали' : 'Новый тип детали' }}</span>
          <v-btn-toggle v-model="formMode" density="comfortable" mandatory color="primary" variant="outlined">
            <v-btn value="quick" size="small">Быстро</v-btn>
            <v-btn value="full" size="small">Расширенно</v-btn>
          </v-btn-toggle>
        </v-card-title>

        <v-card-text>
          <v-form ref="formRef" @submit.prevent="save" @keydown.ctrl.enter.prevent="save">
            <v-text-field
              v-model="form.name"
              label="Название детали"
              :rules="[v => !!v || 'Название обязательно']"
              variant="outlined"
              density="compact"
              autofocus
              class="mb-3"
            />

            <v-select
              v-model="form.edge_processing"
              :items="edgeOptions"
              item-title="title"
              item-value="value"
              label="Обработка торцов"
              variant="outlined"
              density="compact"
              class="mb-3"
            >
              <template #selection="{ item }">
                <v-chip size="small">
                  <v-icon start size="16">{{ item.raw.icon }}</v-icon>
                  {{ item.raw.title }}
                </v-chip>
              </template>
              <template #item="{ props, item }">
                <v-list-item v-bind="props">
                  <template #prepend>
                    <v-icon class="mr-3">{{ item.raw.icon }}</v-icon>
                  </template>
                  <template #title>
                    {{ item.raw.title }}
                  </template>
                  <template #subtitle>
                    {{ item.raw.summary }}
                  </template>
                </v-list-item>
              </template>
            </v-select>

            <v-alert density="compact" variant="tonal" type="info" class="mb-4">
              {{ getEdgeSummary(form.edge_processing) }}
            </v-alert>

            <template v-if="formMode === 'full'">
              <div>
                <div class="d-flex align-center justify-space-between mb-4">
                  <h4 class="text-subtitle-1">Операции</h4>
                  <v-btn
                    size="small"
                    variant="outlined"
                    prepend-icon="mdi-plus"
                    @click="openAddComponentDialog"
                  >
                    Добавить
                  </v-btn>
                </div>

                <div v-if="!form.components?.length" class="text-center py-6">
                  <v-icon size="48" color="grey-lighten-1" class="mb-3">mdi-wrench</v-icon>
                  <div class="text-body-2 text-medium-emphasis">
                    Операции не добавлены
                  </div>
                </div>

                <div v-else class="d-flex flex-wrap gap-2">
                  <v-chip
                    v-for="(comp, index) in form.components"
                    :key="`${comp.id}-${index}`"
                    closable
                    @click:close="removeComponent(comp)"
                    variant="outlined"
                    size="large"
                    class="operation-chip"
                  >
                    {{ getOperationName(comp.id) }}

                    <span class="operation-qty-badge">
                      {{ comp.quantity }} {{ getOperationUnit(comp.id) }}
                    </span>

                    <v-menu activator="parent">
                      <v-list density="compact">
                        <v-list-item @click="editComponentQuantity(index)">
                          <v-list-item-title>Изменить количество</v-list-item-title>
                        </v-list-item>
                        <v-list-item @click="editComponentOperation(index)">
                          <v-list-item-title>Заменить операцию</v-list-item-title>
                        </v-list-item>
                      </v-list>
                    </v-menu>
                  </v-chip>
                </div>
              </div>
            </template>
          </v-form>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn color="primary" variant="flat" type="submit" @click="save">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="addComponentDialog" max-width="800">
      <v-card class="soft-content-card soft-dialog-card">
        <v-card-title class="d-flex align-center justify-space-between">
          <span class="text-h6">Выбор операции</span>
          <v-btn icon variant="text" @click="addComponentDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>

        <v-card-text>
          <v-text-field
            ref="searchField"
            v-model="operationSearch"
            label="Поиск по названию или категории"
            prepend-inner-icon="mdi-magnify"
            clearable
            autofocus
            @update:model-value="filterOperations"
          />

          <v-data-table
            :headers="operationHeaders"
            :items="filteredOperations"
            :loading="loadingOperations"
            density="comfortable"
            hover
            class="mt-4 soft-data-table"
            @click:row="(event: any, { item }: any) => selectOperation(item)"
          >
            <template #item.cost_per_unit="{ item }">
              <div class="text-right">
                <strong>{{ parseFloat(item.cost_per_unit).toFixed(2) }} ₽</strong>
                <span class="text-caption ml-1">/{{ item.unit }}</span>
              </div>
            </template>

            <template #no-data>
              <div class="text-center py-8">
                <v-icon size="64" color="grey-lighten-1">mdi-magnify</v-icon>
                <div class="mt-4 text-body-1">Операции не найдены</div>
              </div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="quantityDialog" max-width="400">
      <v-card class="soft-content-card soft-dialog-card">
        <v-card-title>Изменить количество</v-card-title>
        <v-card-text>
          <v-text-field
            v-model.number="tempQuantity"
            type="number"
            label="Количество"
            :min="0.01"
            :step="0.01"
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="quantityDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="applyQuantity">Применить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup lang="ts">
import { ref, onMounted, watch, nextTick, computed } from 'vue'
import api from '@/api/axios'

const dialog = ref(false)
const editing = ref(false)
const addComponentDialog = ref(false)
const quantityDialog = ref(false)
const formMode = ref<'quick' | 'full'>('quick')

const replaceIndex = ref<number | null>(null)
const tempQuantity = ref(1)
const operationSearch = ref('')
const searchQuery = ref('')
const edgeFilter = ref<'all' | string>('all')
const usageFilter = ref<'all' | 'used' | 'unused'>('all')

const loading = ref(false)
const loadingOperations = ref(false)
const bulkProcessing = ref(false)

const detailTypes = ref<any[]>([])
const allOperations = ref<any[]>([])
const filteredOperations = ref<any[]>([])
const selectedDetailTypeIds = ref<number[]>([])
const bulkAction = ref<'set_edge' | 'delete' | null>(null)
const bulkEdgeScheme = ref<string>('none')

const formRef = ref()
const searchField = ref()

const form = ref({
  id: null,
  name: '',
  edge_processing: 'none',
  components: [] as Array<{ type: string; id: number; quantity: number }>
})

const edgeOptions = [
  { value: 'none', title: 'Без обработки', icon: 'mdi-minus', summary: 'Кромка не применяется' },
  { value: 'O', title: 'Вкруг (O)', icon: 'mdi-circle-outline', summary: 'Верх, низ, левая и правая стороны' },
  { value: '=', title: 'Параллельно длине (=)', icon: 'mdi-arrow-left-right', summary: 'Верх и низ' },
  { value: '||', title: 'Параллельно ширине (||)', icon: 'mdi-arrow-up-down', summary: 'Левая и правая стороны' },
  { value: 'L', title: 'Г-образно (L)', icon: 'mdi-vector-square', summary: 'Верх и левая сторона' },
  { value: 'П', title: 'П-образно (П)', icon: 'mdi-alpha-p-box-outline', summary: 'Верх, левая и правая стороны' }
]

const edgeFilterOptions = [
  { value: 'all', title: 'Все схемы' },
  ...edgeOptions.map((opt) => ({ value: opt.value, title: opt.title }))
]

const usageFilterOptions = [
  { value: 'all', title: 'Все' },
  { value: 'used', title: 'Только используемые' },
  { value: 'unused', title: 'Неиспользуемые' }
]

const quickTemplates = [
  { name: 'Полка', edge_processing: '=' },
  { name: 'Боковина', edge_processing: '||' },
  { name: 'Дно', edge_processing: 'none' },
  { name: 'Фасадная деталь', edge_processing: 'O' }
]

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Обработка торцов', key: 'edge_processing' },
  { title: 'Используется в проектах', key: 'positions_count', align: 'center' as const },
  { title: 'Операций', key: 'components', align: 'center' as const },
  { title: 'Тип', key: 'origin', align: 'center' as const },
  { title: 'Действия', key: 'actions', align: 'end' as const, sortable: false }
]

const operationHeaders = [
  { title: 'Наименование', key: 'name' },
  { title: 'Категория', key: 'category' },
  { title: 'Цена за единицу', key: 'cost_per_unit', align: 'end' as const }
]

const bulkActionOptions = [
  { value: 'set_edge', title: 'Изменить схему торцов' },
  { value: 'delete', title: 'Удалить выбранные' }
]

const getEdgeLabel = (value: string) => edgeOptions.find(o => o.value === value)?.title || value
const getEdgeIcon = (value: string) => edgeOptions.find(o => o.value === value)?.icon || 'mdi-minus'
const getEdgeSummary = (value: string) => edgeOptions.find(o => o.value === value)?.summary || 'Схема не выбрана'

const isEditable = (item: any) => item?.origin !== 'system'

const filteredDetailTypes = computed(() => {
  const term = searchQuery.value.trim().toLowerCase()
  return detailTypes.value.filter((item) => {
    const bySearch = !term || String(item.name || '').toLowerCase().includes(term)
    const byEdge = edgeFilter.value === 'all' || item.edge_processing === edgeFilter.value
    const count = Number(item.positions_count || 0)
    const byUsage = usageFilter.value === 'all'
      || (usageFilter.value === 'used' && count > 0)
      || (usageFilter.value === 'unused' && count === 0)
    return bySearch && byEdge && byUsage
  })
})

const selectedItems = computed(() =>
  detailTypes.value.filter((item) => selectedDetailTypeIds.value.includes(item.id))
)

const selectedEditableItems = computed(() => selectedItems.value.filter((item) => isEditable(item)))

const bulkActionReady = computed(() => {
  if (bulkProcessing.value || selectedEditableItems.value.length === 0 || !bulkAction.value) return false
  if (bulkAction.value === 'set_edge') return !!bulkEdgeScheme.value
  return true
})

const fetchAll = async () => {
  loading.value = true
  try {
    const [typesRes, opsRes] = await Promise.all([
      api.get('/api/detail-types'),
      api.get('/api/operations')
    ])
    detailTypes.value = typesRes.data
    allOperations.value = opsRes.data
    filteredOperations.value = [...allOperations.value]
  } catch (e) {
    console.error(e)
    alert('Не удалось загрузить данные')
  } finally {
    loading.value = false
  }
}

const filterOperations = () => {
  const term = operationSearch.value.toLowerCase().trim()
  if (!term) {
    filteredOperations.value = [...allOperations.value]
    return
  }
  filteredOperations.value = allOperations.value.filter(op =>
    String(op.name || '').toLowerCase().includes(term) ||
    String(op.category || '').toLowerCase().includes(term)
  )
}

const resolveId = (idOrComp: any) => {
  if (typeof idOrComp === 'number') return idOrComp
  if (!idOrComp) return null
  return idOrComp.id ?? idOrComp.operation_id ?? idOrComp.op_id ?? null
}

const getOperationName = (idOrComp: any) => {
  const id = resolveId(idOrComp)
  if (id == null) return 'Операция'
  const op = allOperations.value.find(o => o.id === id)
  return op?.name || `Операция #${id}`
}

const getOperationUnit = (idOrComp: any) => {
  const id = resolveId(idOrComp)
  if (id == null) return ''
  const op = allOperations.value.find(o => o.id === id)
  return op?.unit || ''
}

const openCreateDialog = (template?: { name: string; edge_processing: string }) => {
  editing.value = false
  formMode.value = 'quick'
  form.value = {
    id: null,
    name: template?.name || '',
    edge_processing: template?.edge_processing || 'none',
    components: []
  }
  dialog.value = true
  nextTick(() => formRef.value?.resetValidation())
}

const edit = (item: any) => {
  if (!isEditable(item)) return
  editing.value = true
  formMode.value = 'full'
  form.value = {
    id: item.id,
    name: item.name,
    edge_processing: item.edge_processing || 'none',
    components: item.components?.map((c: any) => ({ ...c })) || []
  }
  dialog.value = true
}

const closeDialog = () => {
  dialog.value = false
  formRef.value?.reset()
}

const remove = async (item: any) => {
  if (!isEditable(item)) return
  const usage = Number(item.positions_count || 0)
  const message = usage > 0
    ? `Тип используется в ${usage} позициях. Удалить?`
    : 'Удалить тип детали?'
  if (!confirm(message)) return

  try {
    await api.delete(`/api/detail-types/${item.id}`)
    await fetchAll()
  } catch (e) {
    alert('Ошибка при удалении')
  }
}

const applyBulkAction = async () => {
  if (!bulkActionReady.value) return

  const items = selectedEditableItems.value
  if (items.length === 0) return

  const confirmed = confirm(
    bulkAction.value === 'delete'
      ? `Удалить ${items.length} выбранных типов?`
      : `Изменить схему торцов для ${items.length} выбранных типов?`
  )
  if (!confirmed) return

  bulkProcessing.value = true
  let done = 0
  try {
    for (const item of items) {
      if (bulkAction.value === 'set_edge') {
        await api.put(`/api/detail-types/${item.id}`, {
          name: item.name,
          edge_processing: bulkEdgeScheme.value,
          components: item.components || []
        })
      } else if (bulkAction.value === 'delete') {
        await api.delete(`/api/detail-types/${item.id}`)
      }
      done += 1
    }

    selectedDetailTypeIds.value = []
    bulkAction.value = null
    await fetchAll()
    alert(`Готово: ${done}`)
  } catch (e) {
    console.error(e)
    alert('Массовая операция завершилась с ошибкой')
    await fetchAll()
  } finally {
    bulkProcessing.value = false
  }
}

const openAddComponentDialog = () => {
  replaceIndex.value = null
  addComponentDialog.value = true
  operationSearch.value = ''
  filterOperations()
  nextTick(() => searchField.value?.focus())
}

const editComponentOperation = (index: number) => {
  replaceIndex.value = index
  addComponentDialog.value = true
  nextTick(() => searchField.value?.focus())
}

const editComponentQuantity = (index: number) => {
  tempQuantity.value = form.value.components[index]?.quantity || 1
  replaceIndex.value = index
  quantityDialog.value = true
}

const applyQuantity = () => {
  if (replaceIndex.value !== null && tempQuantity.value > 0) {
    form.value.components[replaceIndex.value]!.quantity = tempQuantity.value
  }
  quantityDialog.value = false
  replaceIndex.value = null
}

const selectOperation = (item: any) => {
  const op = item
  if (!op?.id) return

  if (replaceIndex.value !== null) {
    form.value.components[replaceIndex.value]!.id = op.id
    form.value.components[replaceIndex.value]!.type = 'operation'
  } else {
    form.value.components.push({
      type: 'operation',
      id: op.id,
      quantity: 1
    })
  }
  addComponentDialog.value = false
  replaceIndex.value = null
}

const removeComponent = (arg: any) => {
  if (typeof arg === 'number') {
    form.value.components.splice(arg, 1)
    return
  }
  const comp = arg
  const idx = form.value.components.findIndex((c: any) => c === comp || c.id === comp.id)
  if (idx !== -1) form.value.components.splice(idx, 1)
}

const save = async () => {
  const { valid } = await formRef.value.validate()
  if (!valid) return

  try {
    if (editing.value) {
      await api.put(`/api/detail-types/${form.value.id}`, form.value)
    } else {
      await api.post('/api/detail-types', form.value)
    }
    dialog.value = false
    await fetchAll()
  } catch (e) {
    console.error(e)
    alert('Не удалось сохранить')
  }
}

onMounted(fetchAll)

watch(addComponentDialog, (val) => {
  if (val) nextTick(() => searchField.value?.focus())
})
</script>

<style scoped>
@import '@/assets/soft-cards.css';

.operation-chip {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border-color: rgba(0, 0, 0, 0.08);
}

.operation-qty-badge {
  background: rgba(0, 0, 0, 0.04);
  padding: 2px 6px;
  border-radius: 6px;
  margin-left: 8px;
  font-weight: 600;
  font-size: 12px;
}

:deep(.v-theme--dark) .operation-chip {
  border-color: rgba(255, 255, 255, 0.12);
}

:deep(.v-theme--dark) .operation-qty-badge {
  background: rgba(255, 255, 255, 0.08);
}
</style>
