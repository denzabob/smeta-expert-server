<template>
  <div class="import-items-table">
    <div v-if="loading" class="import-items-table__state">
      <v-progress-circular indeterminate size="20" width="2" color="primary" />
      <span>Загрузка строк...</span>
    </div>

    <div v-else-if="error" class="import-items-table__state import-items-table__state--error">
      {{ error }}
    </div>

    <div v-else-if="items.length === 0" class="import-items-table__state">
      Строки не найдены
    </div>

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
            <v-chip size="x-small" :color="statusColor(item.status)" variant="tonal">
              {{ statusLabel(item.status) }}
            </v-chip>
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
}

.import-items-table__state {
  min-height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: rgba(15, 23, 42, 0.72);
  text-align: center;
}

.import-items-table__state--error {
  color: rgb(185, 28, 28);
}

.import-items-table__table {
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 16px;
  overflow: hidden;
}

.import-items-table__name {
  font-weight: 600;
  color: rgb(15, 23, 42);
}

.import-items-table__actions {
  min-width: 320px;
}

.import-items-table__pending {
  display: grid;
  gap: 8px;
  padding: 10px 0;
}

.import-items-table__pending-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}

.import-items-table__row-error {
  font-size: 12px;
  line-height: 1.4;
  color: rgb(185, 28, 28);
}

.import-items-table__linked,
.import-items-table__ignored {
  font-size: 13px;
  color: rgba(15, 23, 42, 0.72);
}

@media (max-width: 960px) {
  .import-items-table__actions {
    min-width: 240px;
  }
}
</style>
