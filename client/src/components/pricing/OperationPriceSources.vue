<template>
  <div>
    <div class="sources-section-header">
      <div class="sources-section-heading">
        <span class="sources-section-title">Цена операции</span>
        <v-chip size="x-small" color="info" variant="tonal">
          {{ sources.length }}
        </v-chip>
        <span
          v-if="refreshing"
          class="sources-refresh-indicator"
          role="button"
          tabindex="0"
          @click="emit('refresh')"
          @keydown.enter.prevent="emit('refresh')"
        >
          Обновлено
        </span>
      </div>
      <v-btn
        size="small"
        variant="tonal"
        color="primary"
        prepend-icon="mdi-plus"
        :loading="createSubmitting"
        @click="openCreateDialog"
      >
        Добавить источник цены
      </v-btn>
    </div>

    <div v-if="actionInfo" class="sources-context-note">
      {{ actionInfo }}
    </div>

    <div v-if="actionError" class="source-action-error">
      {{ actionError }}
    </div>

    <div v-if="loading" class="sources-loading">
      <v-progress-circular indeterminate size="20" width="2" />
      <span>Загрузка...</span>
    </div>
    <template v-else-if="sources.length > 0">
      <div
        v-if="activeSource"
        class="source-row source-row--selected"
      >
        <div class="source-row-top">
          <div class="source-row-title-group">
            <span class="source-name">{{ activeSource.source_name || sourceTypeLabel(activeSource.type) }}</span>
            <v-chip
              size="x-small"
              color="primary"
              variant="flat"
            >
              Основная
            </v-chip>
            <v-chip
              size="x-small"
              color="secondary"
              variant="tonal"
            >
              {{ sourceTypeLabel(activeSource.type) }}
            </v-chip>
          </div>
          <div class="source-row-actions">
            <span class="source-date">{{ formatDate(activeSource.created_at) }}</span>
            <v-btn
              size="x-small"
              variant="text"
              color="error"
              class="source-row-action"
              :loading="isDeleteLoading(activeSource.id)"
              :disabled="isBusy"
              @click="emit('delete', activeSource)"
            >
              Удалить
            </v-btn>
          </div>
        </div>
        <div class="source-row-bottom">
          <span class="source-price">
            {{ formatPrice(activeSource.value) }} ₽ / {{ normalizeUnitLabel(activeSource.unit) || defaultUnit || 'ед.' }}
          </span>
        </div>
      </div>

      <div v-if="inactiveSources.length > 0" class="sources-list-label">
        Другие источники:
      </div>
      <div
        v-for="source in inactiveSources"
        :key="source.id"
        class="source-row"
      >
        <div class="source-row-top">
          <div class="source-row-title-group">
            <span class="source-name">{{ source.source_name || sourceTypeLabel(source.type) }}</span>
            <v-chip
              size="x-small"
              color="secondary"
              variant="tonal"
            >
              {{ sourceTypeLabel(source.type) }}
            </v-chip>
          </div>
          <div class="source-row-actions">
            <span class="source-date">{{ formatDate(source.created_at) }}</span>
            <v-btn
              size="x-small"
              variant="tonal"
              color="primary"
              class="source-row-action"
              :loading="isActivateLoading(source.id)"
              :disabled="isBusy"
              @click="emit('activate', source)"
            >
              Сделать основным
            </v-btn>
            <v-btn
              size="x-small"
              variant="text"
              color="error"
              class="source-row-action"
              :loading="isDeleteLoading(source.id)"
              :disabled="isBusy"
              @click="emit('delete', source)"
            >
              Удалить
            </v-btn>
          </div>
        </div>
        <div class="source-row-bottom">
          <span class="source-price">
            {{ formatPrice(source.value) }} ₽ / {{ normalizeUnitLabel(source.unit) || defaultUnit || 'ед.' }}
          </span>
        </div>
      </div>
    </template>
    <div v-else class="sources-empty sources-empty--compact">
      <v-icon size="32" color="grey-lighten-2">mdi-currency-rub-off</v-icon>
      <p class="sources-empty-note">
        Цена не задана. Добавьте источник цены.
      </p>
    </div>

    <v-dialog v-model="createDialogOpen" max-width="520">
      <v-card>
        <v-card-title>Добавить источник цены</v-card-title>
        <v-card-text class="quick-create-dialog">
          <v-alert
            v-if="visibleCreateError"
            type="error"
            variant="tonal"
            density="compact"
          >
            {{ visibleCreateError }}
          </v-alert>

          <v-select
            v-model="createForm.type"
            :items="[
              { title: 'Ручной', value: 'manual' },
              { title: 'Импорт', value: 'import' },
              { title: 'Внешний', value: 'external' },
            ]"
            item-title="title"
            item-value="value"
            label="Тип источника"
            variant="outlined"
            density="compact"
            hide-details
          />

          <v-text-field
            v-model.number="createForm.value"
            type="number"
            min="0.01"
            step="0.01"
            label="Цена"
            variant="outlined"
            density="compact"
            hide-details
          />

          <v-text-field
            v-model="createForm.unit"
            label="Единица"
            variant="outlined"
            density="compact"
            hide-details
          />

          <v-text-field
            v-model="createForm.source_name"
            label="Название источника"
            variant="outlined"
            density="compact"
            hide-details
            placeholder="Например, Прайс поставщика"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="createSubmitting" @click="createDialogOpen = false">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="createSubmitting || isCreateLoading"
            @click="emitCreate"
          >
            Добавить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type SourceType = 'manual' | 'import' | 'external'

interface OperationPriceSourceRow {
  id: number
  type: SourceType
  value: string | number | null
  unit: string | null
  source_name: string | null
  document_ref?: string | null
  created_at: string | null
  is_active: boolean
}

interface SourceActionPending {
  action: 'create' | 'activate' | 'delete'
  sourceId: number | null
}

interface PriceSourceCreatePayload {
  type: SourceType
  value: number | null
  unit: string
  source_name: string
}

const props = withDefaults(defineProps<{
  operationId: number | null
  sources: OperationPriceSourceRow[]
  activeSource: OperationPriceSourceRow | null
  loading: boolean
  defaultUnit?: string | null
  refreshing?: boolean
  actionInfo?: string | null
  actionError?: string | null
  actionPending?: SourceActionPending | null
  createSubmitting?: boolean
  createError?: string | null
  createSuccessToken?: number
}>(), {
  defaultUnit: null,
  refreshing: false,
  actionInfo: null,
  actionError: null,
  actionPending: null,
  createSubmitting: false,
  createError: null,
  createSuccessToken: 0,
})

const emit = defineEmits<{
  (event: 'create', payload: PriceSourceCreatePayload): void
  (event: 'activate', payload: OperationPriceSourceRow): void
  (event: 'delete', payload: OperationPriceSourceRow): void
  (event: 'refresh'): void
}>()

const createDialogOpen = ref(false)
const createAttempted = ref(false)
const createForm = ref<PriceSourceCreatePayload>({
  type: 'manual',
  value: null,
  unit: '',
  source_name: '',
})

const inactiveSources = computed<OperationPriceSourceRow[]>(() => (
  props.sources.filter((source) => !source.is_active)
))
const visibleCreateError = computed<string | null>(() => (
  createAttempted.value ? props.createError : null
))
const isBusy = computed<boolean>(() => props.actionPending !== null || props.createSubmitting)
const isCreateLoading = computed<boolean>(() => props.actionPending?.action === 'create')

function isActivateLoading(sourceId: number): boolean {
  return props.actionPending?.action === 'activate' && props.actionPending.sourceId === sourceId
}

function isDeleteLoading(sourceId: number): boolean {
  return props.actionPending?.action === 'delete' && props.actionPending.sourceId === sourceId
}

function formatPrice(val: string | number | null | undefined): string {
  if (val === null || val === undefined) return '—'
  const numberValue = typeof val === 'string' ? Number(val) : val
  if (!Number.isFinite(numberValue)) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(numberValue)
}

function formatDate(val?: string | null): string {
  if (!val) return '—'
  const date = new Date(val)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString('ru-RU')
}

function normalizeUnitLabel(unit?: string | null): string | null {
  if (!unit) return null

  const compact = unit.toLowerCase().trim().replace(/[\s.,·]/g, '')
  const map: Record<string, string> = {
    'м2': 'м²',
    'm2': 'м²',
    'м^2': 'м²',
    'м²': 'м²',
    'квм': 'м²',
    'мп': 'м.п.',
    'пм': 'м.п.',
    'погм': 'м.п.',
    'мпог': 'м.п.',
    'шт': 'шт.',
    'шт.': 'шт.',
    'рез': 'рез',
    'дет': 'деталь',
    'деталь': 'деталь',
    'лист': 'лист',
  }

  return map[compact] ?? unit.trim()
}

function sourceTypeLabel(type?: SourceType | null): string {
  if (type === 'manual') return 'Ручной'
  if (type === 'import') return 'Импорт'
  if (type === 'external') return 'Внешний'
  return 'Источник'
}

function resetCreateForm() {
  createAttempted.value = false
  createForm.value = {
    type: 'manual',
    value: null,
    unit: props.defaultUnit ?? '',
    source_name: '',
  }
}

function openCreateDialog() {
  resetCreateForm()
  createDialogOpen.value = true
}

function emitCreate() {
  createAttempted.value = true
  emit('create', {
    type: createForm.value.type,
    value: createForm.value.value,
    unit: createForm.value.unit,
    source_name: createForm.value.source_name,
  })
}

watch(() => props.createSuccessToken, (nextValue, previousValue) => {
  if (nextValue === previousValue) return
  createDialogOpen.value = false
  resetCreateForm()
})

watch(() => props.operationId, () => {
  createDialogOpen.value = false
  resetCreateForm()
})
</script>

<style scoped>
.quick-create-dialog {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.sources-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.sources-section-heading {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.sources-refresh-indicator {
  font-size: 12px;
  line-height: 1.4;
  color: rgba(var(--v-theme-primary), 0.92);
  cursor: pointer;
}

.sources-section-title {
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.7);
}

.sources-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 0;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.4);
}

.sources-context-note {
  margin-bottom: 10px;
  font-size: 12px;
  line-height: 1.4;
  color: rgba(var(--v-theme-primary), 0.92);
}

.sources-list-label {
  margin-bottom: 10px;
  font-size: 12px;
  line-height: 1.4;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.64);
}

.source-action-error {
  margin-bottom: 10px;
  padding: 8px 10px;
  border: 1px solid rgba(var(--v-theme-error), 0.2);
  border-radius: 8px;
  background: rgba(var(--v-theme-error), 0.05);
  color: rgb(var(--v-theme-error));
  font-size: 12px;
  line-height: 1.4;
}

.source-row {
  padding: 10px 12px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 6px;
  margin-bottom: 8px;
}

.source-row--selected {
  border-color: rgba(var(--v-theme-primary), 0.3);
  background: rgba(var(--v-theme-primary), 0.05);
}

.source-row-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
}

.source-row-title-group {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.source-name {
  font-size: 13px;
  font-weight: 500;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.source-row-bottom {
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.source-date {
  color: rgba(0, 0, 0, 0.45);
  font-size: 11px;
  white-space: nowrap;
}

.source-row-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  flex-shrink: 0;
  flex-wrap: wrap;
}

.source-price {
  color: rgba(0, 0, 0, 0.7);
  font-weight: 600;
}

.source-row-action {
  flex-shrink: 0;
}

.sources-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 32px 16px;
  text-align: center;
  color: rgba(0, 0, 0, 0.4);
  font-size: 13px;
}

.sources-empty-note {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.3);
  max-width: 280px;
}

.sources-empty--compact {
  padding: 12px 0 16px;
}
</style>
