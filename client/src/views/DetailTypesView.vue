<template>
  <PageContainer class="soft-page detail-types-view">
    <PageHeader
      title="Типы деталей"
      subtitle="Справочник типов деталей, схем торцов и привязанных операций для редактора проектов."
    >
      <template #actions>
        <ButtonGroup>
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
            variant="flat"
            @click="openCreateDialog()"
          >
            Новый тип
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard class="soft-content-card soft-data-card detail-types-view__card" variant="outlined">
      <template #title>Типы деталей</template>
      <template #subtitle>
        Фильтры, массовые действия и список типов собраны в единой рабочей панели.
      </template>

      <div class="detail-types-view__content md3-section-stack">
        <AppDataTableShell>
        <TableToolbar>
          <template #search>
          <v-text-field
            v-model="searchQuery"
            label="Поиск по названию"
            prepend-inner-icon="mdi-magnify"
            clearable
            variant="outlined"
            density="compact"
            hide-details
            class="detail-types-view__search ds-table-toolbar__search"
          />
          </template>

          <template #filters>
            <v-select
              v-model="edgeFilter"
              :items="edgeFilterOptions"
              item-title="title"
              item-value="value"
              label="Схема торцов"
              variant="outlined"
              density="compact"
              hide-details
              class="detail-types-view__filter"
            />
            <v-select
              v-model="usageFilter"
              :items="usageFilterOptions"
              item-title="title"
              item-value="value"
              label="Использование"
              variant="outlined"
              density="compact"
              hide-details
              class="detail-types-view__filter"
            />
          </template>
        </TableToolbar>

        <v-alert
          v-if="selectedDetailTypeIds.length > 0"
          density="compact"
          variant="tonal"
          type="info"
          class="detail-types-view__bulk"
        >
          Выбрано: {{ selectedDetailTypeIds.length }}
          <template #append>
            <div class="detail-types-view__bulk-actions">
              <v-select
                v-model="bulkAction"
                :items="bulkActionOptions"
                item-title="title"
                item-value="value"
                label="Действие"
                density="compact"
                variant="outlined"
                hide-details
                class="detail-types-view__bulk-field"
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
                class="detail-types-view__bulk-field detail-types-view__bulk-field--wide"
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

        <v-data-table
          :headers="headers"
          :items="filteredDetailTypes"
          :loading="loading"
          density="comfortable"
          hover
          class="soft-data-table detail-types-view__table"
          :no-data-text="loading ? '' : 'Нет типов деталей'"
          item-value="id"
          show-select
          v-model="selectedDetailTypeIds"
        >
          <template #item.name="{ item }">
            <div class="font-weight-medium">{{ item.name }}</div>
          </template>

          <template #item.edge_processing="{ item }">
            <StatusChip size="small" color="primary" variant="flat">
              <v-icon start size="14">{{ getEdgeIcon(item.edge_processing) }}</v-icon>
              {{ getEdgeLabel(item.edge_processing) }}
            </StatusChip>
          </template>

          <template #item.positions_count="{ item }">
            <StatusChip
              size="small"
              :color="Number(item.positions_count || 0) > 0 ? 'primary' : 'grey'"
              variant="tonal"
            >
              {{ item.positions_count || 0 }}
            </StatusChip>
          </template>

          <template #item.components="{ item }">
            <span class="text-caption text-medium-emphasis">
              {{ item.components?.length || 0 }} операций
            </span>
          </template>

          <template #item.origin="{ item }">
            <StatusChip
              :status="item.origin === 'system' ? 'disabled' : 'active'"
              :label="item.origin === 'system' ? 'Системный' : 'Пользовательский'"
            />
          </template>

          <template #item.actions="{ item }">
            <AppRowActions class="detail-types-view__row-actions" dense>
              <v-tooltip text="Редактировать" location="top">
                <template #activator="{ props }">
                  <v-btn
                    v-bind="props"
                    icon="mdi-pencil"
                    size="small"
                    variant="text"
                    :disabled="!isEditable(item)"
                    :aria-label="isEditable(item) ? 'Редактировать тип детали' : 'Системный тип не редактируется'"
                    @click="edit(item)"
                  />
                </template>
              </v-tooltip>
              <v-tooltip text="Удалить" location="top">
                <template #activator="{ props }">
                  <v-btn
                    v-bind="props"
                    icon="mdi-delete"
                    size="small"
                    variant="text"
                    color="error"
                    :disabled="!isEditable(item)"
                    :aria-label="isEditable(item) ? 'Удалить тип детали' : 'Системный тип не удаляется'"
                    @click="remove(item)"
                  />
                </template>
              </v-tooltip>
            </AppRowActions>
          </template>
        </v-data-table>
        </AppDataTableShell>
      </div>
    </SectionCard>

    <v-dialog v-model="dialog" max-width="760" persistent>
      <v-card class="soft-content-card soft-dialog-card detail-types-view__dialog-card">
        <v-card-title class="detail-types-view__dialog-header">
          <div>
            <div class="detail-types-view__dialog-title">
              {{ editing ? 'Редактировать тип детали' : 'Новый тип детали' }}
            </div>
            <div class="detail-types-view__dialog-subtitle">
              Настройте схему торцов и, при необходимости, операции расчёта.
            </div>
          </div>
          <v-btn-toggle
            v-model="formMode"
            density="comfortable"
            mandatory
            color="primary"
            variant="outlined"
            class="detail-types-view__mode-toggle"
          >
            <v-btn value="quick" size="small">Быстро</v-btn>
            <v-btn value="full" size="small">Расширенно</v-btn>
          </v-btn-toggle>
        </v-card-title>

        <v-card-text>
          <v-form ref="formRef" class="md3-form-stack" @submit.prevent="save" @keydown.ctrl.enter.prevent="save">
            <v-text-field
              v-model="form.name"
              label="Название детали"
              :rules="[v => !!v || 'Название обязательно']"
              variant="outlined"
              density="compact"
              autofocus
            />

            <v-select
              v-model="form.edge_processing"
              :items="edgeOptions"
              item-title="title"
              item-value="value"
              label="Обработка торцов"
              variant="outlined"
              density="compact"
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

            <v-alert density="compact" variant="tonal" type="info">
              {{ getEdgeSummary(form.edge_processing) }}
            </v-alert>

            <template v-if="formMode === 'full'">
              <div class="md3-section-block">
                <div class="md3-section-block__header detail-types-view__components-header">
                  <div>
                    <div class="md3-section-block__title">Операции</div>
                    <div class="md3-section-block__subtitle">Операции будут применяться к этому типу детали.</div>
                  </div>
                  <v-btn
                    size="small"
                    variant="outlined"
                    prepend-icon="mdi-plus"
                    @click="openAddComponentDialog"
                  >
                    Добавить
                  </v-btn>
                </div>

                <AppStateBlock
                  v-if="!form.components?.length"
                  class="detail-types-view__empty"
                  icon="mdi-wrench"
                  title="Операции не добавлены"
                  density="compact"
                />

                <div v-else class="detail-types-view__chips">
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

        <AppActionFooter class="detail-types-view__dialog-actions">
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn color="primary" variant="flat" type="submit" @click="save">
            Сохранить
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-dialog v-model="addComponentDialog" max-width="800">
      <v-card class="soft-content-card soft-dialog-card detail-types-view__dialog-card">
        <v-card-title class="detail-types-view__dialog-header">
          <div>
            <div class="detail-types-view__dialog-title">Выбор операции</div>
            <div class="detail-types-view__dialog-subtitle">Найдите операцию и выберите строку из таблицы.</div>
          </div>
          <v-btn icon variant="text" @click="addComponentDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>

        <v-card-text class="md3-section-stack">
          <v-text-field
            ref="searchField"
            v-model="operationSearch"
            label="Поиск по названию или категории"
            prepend-inner-icon="mdi-magnify"
            clearable
            autofocus
            variant="outlined"
            density="compact"
            hide-details
            @update:model-value="filterOperations"
          />

          <v-data-table
            :headers="operationHeaders"
            :items="filteredOperations"
            :loading="loadingOperations"
            density="comfortable"
            hover
            class="soft-data-table detail-types-view__table"
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
      <v-card class="soft-content-card soft-dialog-card detail-types-view__dialog-card">
        <v-card-title class="detail-types-view__dialog-header">
          <div>
            <div class="detail-types-view__dialog-title">Изменить количество</div>
            <div class="detail-types-view__dialog-subtitle">Укажите коэффициент операции для типа детали.</div>
          </div>
        </v-card-title>
        <v-card-text class="md3-form-stack">
          <v-text-field
            v-model.number="tempQuantity"
            type="number"
            label="Количество"
            :min="0.01"
            :step="0.01"
            variant="outlined"
            density="compact"
            autofocus
          />
        </v-card-text>
        <AppActionFooter class="detail-types-view__dialog-actions">
          <v-btn variant="text" @click="quantityDialog = false">Отмена</v-btn>
          <v-btn color="primary" @click="applyQuantity">Применить</v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, onMounted, watch, nextTick, computed } from 'vue'
import api from '@/api/axios'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'

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
/* soft-cards classes now live globally in design-system.scss */

.detail-types-view__content {
  gap: var(--ds-space-14);
}

.detail-types-view__toolbar {
  align-items: stretch;
  padding-bottom: 0;
}

.detail-types-view__search {
  min-width: 260px;
}

.detail-types-view__filters {
  flex: 1 1 auto;
  justify-content: flex-end;
}

.detail-types-view__filter {
  min-width: 190px;
  max-width: 240px;
}

.detail-types-view__bulk {
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-primary), 0.16);
}

.detail-types-view__bulk :deep(.v-alert__content) {
  display: flex;
  align-items: center;
  font-weight: 700;
}

.detail-types-view__bulk-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-8);
  flex-wrap: wrap;
}

.detail-types-view__bulk-field {
  min-width: 170px;
}

.detail-types-view__bulk-field--wide {
  min-width: 220px;
}

.detail-types-view__table {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-12);
  overflow: hidden;
}

.detail-types-view__table :deep(.v-data-table__td),
.detail-types-view__table :deep(.v-data-table__th) {
  vertical-align: middle;
}

.detail-types-view__table :deep(tbody tr:hover) {
  background: var(--ds-surface-hover);
}

.detail-types-view__row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-4);
  min-width: 76px;
}

.detail-types-view__dialog-card {
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.035), transparent 160px),
    var(--ds-surface-card);
}

.detail-types-view__dialog-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-16);
  padding: var(--ds-space-20) var(--ds-space-20) var(--ds-space-14);
  border-bottom: 1px solid var(--ds-divider);
}

.detail-types-view__dialog-title {
  color: var(--ds-text-primary);
  font-size: 20px;
  font-weight: 800;
  line-height: 1.25;
}

.detail-types-view__dialog-subtitle {
  margin-top: var(--ds-space-4);
  color: var(--ds-text-secondary);
  font-size: 13px;
  font-weight: 500;
  line-height: 1.45;
}

.detail-types-view__mode-toggle {
  flex: 0 0 auto;
}

.detail-types-view__components-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-12);
}

.detail-types-view__empty {
  display: grid;
  place-items: center;
  gap: var(--ds-space-8);
  min-height: 120px;
  border: 1px dashed var(--ds-border-color);
  border-radius: var(--ds-radius-12);
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 74%, transparent);
}

.detail-types-view__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-8);
}

.detail-types-view__dialog-actions {
  padding: var(--ds-space-14) var(--ds-space-20) var(--ds-space-20);
  border-top: 1px solid var(--ds-divider);
}

.operation-chip {
  background: var(--ds-surface-card-subtle);
  color: var(--ds-text-primary);
  border-color: var(--ds-border-color);
  min-height: 36px;
}

.operation-qty-badge {
  background: rgba(var(--v-theme-primary), 0.10);
  color: rgb(var(--v-theme-primary));
  padding: 2px 6px;
  border-radius: var(--ds-radius-8);
  margin-left: 8px;
  font-weight: 700;
  font-size: 12px;
}

@media (max-width: 720px) {
  .detail-types-view__toolbar,
  .detail-types-view__filters,
  .detail-types-view__bulk-actions,
  .detail-types-view__dialog-header,
  .detail-types-view__components-header {
    align-items: stretch;
    flex-direction: column;
  }

  .detail-types-view__search,
  .detail-types-view__filter,
  .detail-types-view__bulk-field,
  .detail-types-view__bulk-field--wide {
    width: 100%;
    max-width: none;
  }
}
</style>
