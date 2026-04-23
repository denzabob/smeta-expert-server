<template>
  <div class="import-items-table">
    <AppStateBlock
      v-if="loading"
      title="Загрузка строк"
      description="Получаем строки выбранного импорта."
      loading
      density="compact"
    />

    <AppStateBlock
      v-else-if="error"
      title="Не удалось загрузить строки"
      :description="error"
      icon="mdi-alert-circle-outline"
      tone="error"
      density="compact"
    />

    <AppStateBlock
      v-else-if="items.length === 0"
      title="Строки не найдены"
      description="В этом импорте нет строк для привязки."
      icon="mdi-table-off"
      density="compact"
    />

    <v-table v-else density="compact" class="import-items-table__table">
      <thead>
        <tr>
          <th>Название</th>
          <th class="text-right">Цена</th>
          <th>Ед.</th>
          <th>Статус</th>
          <th>Действие</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.id">
          <td class="import-items-table__name">{{ item.name }}</td>
          <td class="text-right">{{ formatValue(item.value) }}</td>
          <td>{{ item.unit }}</td>
          <td>
            <StatusChip
              :status="item.status"
              :label="statusLabel(item.status)"
              :color="statusColor(item.status)"
              size="x-small"
            />
          </td>
          <td class="import-items-table__actions">
            <template v-if="item.status === 'pending'">
              <div class="import-items-table__pending">
                <v-select
                  :model-value="selectedOperationIds[item.id] ?? null"
                  :items="operationOptions"
                  :loading="operationsLoading"
                  item-title="title"
                  item-value="value"
                  label="Выбрать операцию"
                  density="comfortable"
                  variant="outlined"
                  hide-details
                  clearable
                  :disabled="isRowBusy(item.id)"
                  @update:model-value="setSelectedOperation(item.id, $event)"
                />

                <div class="import-items-table__pending-actions">
                  <v-btn
                    color="primary"
                    variant="flat"
                    size="small"
                    :disabled="!selectedOperationIds[item.id] || isRowBusy(item.id)"
                    :loading="isBinding(item.id)"
                    @click="bindItem(item)"
                  >
                    Привязать
                  </v-btn>

                  <v-btn
                    variant="text"
                    size="small"
                    color="secondary"
                    :disabled="isRowBusy(item.id)"
                    :loading="isIgnoring(item.id)"
                    @click="ignoreItem(item)"
                  >
                    Игнор
                  </v-btn>
                </div>

                <div v-if="rowErrors[item.id]" class="import-items-table__row-error">
                  {{ rowErrors[item.id] }}
                </div>
              </div>
            </template>

            <div v-else-if="item.status === 'linked'" class="import-items-table__linked">
              Привязано к операции #{{ item.operation_id }}
            </div>

            <div v-else class="import-items-table__ignored">
              Игнорировано
            </div>
          </td>
        </tr>
      </tbody>
    </v-table>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import api from '@/api/axios'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

type ImportItemStatus = 'pending' | 'linked' | 'ignored'

export type ImportItemRow = {
  id: number
  import_id: number
  operation_id: number | null
  name: string
  value: number | null
  unit: string
  parsed_operation_hint: string | null
  status: ImportItemStatus
}

type OperationApiRow = {
  id: number
  name: string
  unit: string | null
  origin?: string | null
  user_id?: number | null
}

type OperationOption = {
  title: string
  value: number
}

type RowActionState = 'bind' | 'ignore' | null

const props = defineProps<{
  items: ImportItemRow[]
  loading?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  (event: 'updated', importId: number): void
}>()

const operationOptions = ref<OperationOption[]>([])
const operationsLoading = ref(false)
const selectedOperationIds = ref<Record<number, number | null>>({})
const rowErrors = ref<Record<number, string | null>>({})
const rowActionState = ref<Record<number, RowActionState>>({})

const hasPendingItems = computed(() => props.items.some((item) => item.status === 'pending'))

watch(
  () => props.items,
  (items) => {
    const knownIds = new Set(items.map((item) => item.id))

    items.forEach((item) => {
      if (!(item.id in selectedOperationIds.value)) {
        selectedOperationIds.value[item.id] = null
      }
      if (!(item.id in rowErrors.value)) {
        rowErrors.value[item.id] = null
      }
      if (!(item.id in rowActionState.value)) {
        rowActionState.value[item.id] = null
      }
    })

    Object.keys(selectedOperationIds.value).forEach((key) => {
      if (!knownIds.has(Number(key))) {
        delete selectedOperationIds.value[Number(key)]
      }
    })

    Object.keys(rowErrors.value).forEach((key) => {
      if (!knownIds.has(Number(key))) {
        delete rowErrors.value[Number(key)]
      }
    })

    Object.keys(rowActionState.value).forEach((key) => {
      if (!knownIds.has(Number(key))) {
        delete rowActionState.value[Number(key)]
      }
    })
  },
  { immediate: true }
)

watch(
  hasPendingItems,
  (value) => {
    if (value && operationOptions.value.length === 0 && !operationsLoading.value) {
      void fetchOperations()
    }
  },
  { immediate: true }
)

onMounted(() => {
  if (hasPendingItems.value && operationOptions.value.length === 0) {
    void fetchOperations()
  }
})

function statusLabel(status: ImportItemStatus): string {
  if (status === 'linked') return 'Привязано'
  if (status === 'ignored') return 'Игнор'
  return 'Не обработано'
}

function statusColor(status: ImportItemStatus): string {
  if (status === 'linked') return 'success'
  if (status === 'ignored') return 'default'
  return 'warning'
}

function formatValue(value: number | null): string {
  if (typeof value !== 'number') return '—'
  return new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 2,
  }).format(value)
}

function setSelectedOperation(itemId: number, value: unknown): void {
  selectedOperationIds.value[itemId] = typeof value === 'number' ? value : null
  rowErrors.value[itemId] = null
}

function isRowBusy(itemId: number): boolean {
  return rowActionState.value[itemId] !== null
}

function isBinding(itemId: number): boolean {
  return rowActionState.value[itemId] === 'bind'
}

function isIgnoring(itemId: number): boolean {
  return rowActionState.value[itemId] === 'ignore'
}

async function fetchOperations(): Promise<void> {
  operationsLoading.value = true
  try {
    const response = await api.get('/api/operations')
    const rows = Array.isArray(response.data) ? (response.data as OperationApiRow[]) : []

    operationOptions.value = rows
      .filter((row) => row.origin === 'user' && row.user_id !== null)
      .map((row) => ({
        title: `${row.name} · ${row.unit || '—'}`,
        value: row.id,
      }))
  } catch {
    operationOptions.value = []
  } finally {
    operationsLoading.value = false
  }
}

async function bindItem(item: ImportItemRow): Promise<void> {
  const operationId = selectedOperationIds.value[item.id]
  if (!operationId) return

  rowErrors.value[item.id] = null
  rowActionState.value[item.id] = 'bind'

  try {
    await api.post(`/api/price-import-items/${item.id}/bind`, {
      operation_id: operationId,
    })
    emit('updated', item.import_id)
  } catch (error: unknown) {
    const status =
      typeof error === 'object' && error !== null ? (error as { response?: { status?: number } }).response?.status : undefined
    const message =
      typeof error === 'object' && error !== null
        ? (error as { response?: { data?: { message?: string } } }).response?.data?.message
        : undefined

    if (status === 422) {
      rowErrors.value[item.id] = 'Единицы не совпадают с операцией'
      if (typeof message === 'string' && !message.toLowerCase().includes('единиц')) {
        rowErrors.value[item.id] = message
      }
    } else {
      rowErrors.value[item.id] = 'Не удалось привязать строку'
    }
  } finally {
    rowActionState.value[item.id] = null
  }
}

async function ignoreItem(item: ImportItemRow): Promise<void> {
  rowErrors.value[item.id] = null
  rowActionState.value[item.id] = 'ignore'

  try {
    await api.post(`/api/price-import-items/${item.id}/ignore`)
    emit('updated', item.import_id)
  } catch {
    rowErrors.value[item.id] = 'Не удалось отметить строку как игнор'
  } finally {
    rowActionState.value[item.id] = null
  }
}
</script>

<style scoped>
.import-items-table {
  width: 100%;
  min-width: 0;
  overflow-x: auto;
}

.import-items-table__table {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  overflow: hidden;
  background: rgba(var(--v-theme-surface-container-lowest), 0.8);
  min-width: 720px;
}

.import-items-table__name {
  font-weight: 600;
  color: var(--ds-text-primary);
}

.import-items-table__actions {
  min-width: 320px;
}

.import-items-table__pending {
  display: grid;
  gap: var(--ds-space-8);
  padding: var(--ds-space-10) 0;
}

.import-items-table__pending-actions {
  display: flex;
  gap: var(--ds-space-8);
  align-items: center;
  flex-wrap: wrap;
}

.import-items-table__row-error {
  font-size: 12px;
  line-height: 1.4;
  color: rgb(var(--v-theme-error));
}

.import-items-table__linked,
.import-items-table__ignored {
  font-size: 13px;
  color: var(--ds-text-secondary);
}

@media (max-width: 960px) {
  .import-items-table__actions {
    min-width: 240px;
  }
}
</style>
