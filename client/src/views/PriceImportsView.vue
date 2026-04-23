<template>
  <PageContainer class="price-imports-view">
    <PageHeader
      title="Импорты"
      subtitle="История загруженных прайсов и текущий статус привязки строк к операциям."
    >
      <template #actions>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreateDialog">
          Создать импорт
        </v-btn>
      </template>
    </PageHeader>

    <v-alert
      v-if="pageError"
      type="error"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      {{ pageError }}
    </v-alert>

    <SectionCard
      class="price-imports-view__card"
      subtitle="Журнал импортов, прогресс обработки и быстрый переход к строкам для ручной привязки."
    >
      <AppStateBlock
        v-if="loading"
        title="Загрузка импортов"
        description="Получаем историю загруженных прайсов."
        loading
      />

      <AppStateBlock
        v-else-if="imports.length === 0"
        title="История импортов пока пуста"
        description="Создайте первый импорт, чтобы привязать строки прайса к операциям."
        icon="mdi-file-import-outline"
      >
        <template #actions>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreateDialog">
            Создать импорт
          </v-btn>
        </template>
      </AppStateBlock>

      <div v-else class="price-imports-view__list">
        <button
          v-for="item in imports"
          :key="item.id"
          type="button"
          class="price-imports-view__item"
          @click="openImport(item)"
        >
          <div class="price-imports-view__item-main">
            <div class="price-imports-view__item-title">Импорт #{{ item.id }}</div>
            <div class="price-imports-view__item-meta">
              {{ item.items_count }} строк | {{ item.linked_count }} привязано | {{ item.pending_count }} осталось
            </div>
          </div>

          <div class="price-imports-view__item-side">
            <StatusChip :status="item.status" :label="statusLabel(item.status)" :color="statusColor(item.status)" />
            <span class="price-imports-view__open-link">Открыть</span>
          </div>
        </button>
      </div>
    </SectionCard>

    <AppDetailDrawer
      :model-value="drawer.open"
      :width="760"
      title="Строки импорта"
      @update:model-value="handleDrawerToggle"
    >
      <template #title>
        Импорт #{{ drawer.importItem?.id ?? '—' }}
      </template>
      <template #subtitle>
        <span v-if="drawer.importItem">
          {{ drawer.importItem.items_count }} строк | {{ drawer.importItem.linked_count }} привязано |
          {{ drawer.importItem.pending_count }} осталось
        </span>
      </template>

      <AppDetailMetaGrid
        v-if="drawer.importItem"
        class="mb-4"
        :items="drawerMetaItems"
      />

      <ImportItemsTable
        :items="drawer.items"
        :loading="drawer.loading"
        :error="drawer.error"
        @updated="handleImportUpdated"
      />
    </AppDetailDrawer>

    <v-dialog v-model="createDialog.open" max-width="860">
      <v-card class="price-imports-view__dialog-card" rounded="xl">
        <v-card-title class="price-imports-view__dialog-title">
          Создать импорт
        </v-card-title>

        <v-card-text class="price-imports-view__dialog-body md3-form-stack">
          <v-alert
            v-if="createDialog.error"
            type="error"
            variant="tonal"
            density="comfortable"
          >
            {{ createDialog.error }}
          </v-alert>

          <div class="price-imports-view__rows md3-section-stack">
            <AppFormSection
              v-for="(row, index) in createDialog.rows"
              :key="row.id"
              :title="`Строка ${index + 1}`"
              compact
            >
              <div class="price-imports-view__row">
                <v-text-field
                  v-model="row.name"
                  label="Название"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="createDialog.attempted ? rowErrors[index]?.name : []"
                />

                <v-text-field
                  :model-value="row.value"
                  label="Цена"
                  variant="outlined"
                  density="comfortable"
                  inputmode="decimal"
                  :error-messages="createDialog.attempted ? rowErrors[index]?.value : []"
                  @update:model-value="updateDraftValue(index, $event)"
                />

                <v-text-field
                  v-model="row.unit"
                  label="Ед."
                  variant="outlined"
                  density="comfortable"
                  :error-messages="createDialog.attempted ? rowErrors[index]?.unit : []"
                />

                <div class="price-imports-view__row-actions">
                  <v-btn
                    variant="text"
                    color="error"
                    size="small"
                    :disabled="createDialog.rows.length <= 1 || createDialog.submitting"
                    @click="removeDraftRow(index)"
                  >
                    Удалить
                  </v-btn>
                </div>
              </div>
            </AppFormSection>
          </div>

          <v-btn
            variant="text"
            color="primary"
            prepend-icon="mdi-plus"
            :disabled="createDialog.submitting"
            @click="addDraftRow"
          >
            Добавить строку
          </v-btn>
        </v-card-text>

        <AppActionFooter>
          <v-btn variant="text" :disabled="createDialog.submitting" @click="closeCreateDialog">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="createDialog.submitting"
            @click="submitCreateImport"
          >
            Сохранить
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import api from '@/api/axios'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDetailDrawer from '@/components/layout/AppDetailDrawer.vue'
import AppDetailMetaGrid from '@/components/layout/AppDetailMetaGrid.vue'
import AppFormSection from '@/components/layout/AppFormSection.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import ImportItemsTable, { type ImportItemRow } from '@/components/imports/ImportItemsTable.vue'

type ImportStatus = 'pending' | 'processed' | 'failed'

type ImportListRow = {
  id: number
  type: 'manual' | 'excel'
  status: ImportStatus
  created_at: string | null
  items_count: number
  linked_count: number
  pending_count: number
}

type DraftImportRow = {
  id: number
  name: string
  value: string
  unit: string
}

type DraftRowErrors = {
  name: string[]
  value: string[]
  unit: string[]
}

const imports = ref<ImportListRow[]>([])
const loading = ref(false)
const pageError = ref<string | null>(null)
let draftRowId = 1

const drawer = ref({
  open: false,
  importItem: null as ImportListRow | null,
  items: [] as ImportItemRow[],
  loading: false,
  error: null as string | null,
})

const createDialog = ref({
  open: false,
  submitting: false,
  error: null as string | null,
  attempted: false,
  rows: [createEmptyDraftRow()] as DraftImportRow[],
})

const rowErrors = computed<DraftRowErrors[]>(() =>
  createDialog.value.rows.map((row) => validateDraftRow(row))
)

const drawerMetaItems = computed(() => {
  const item = drawer.value.importItem
  if (!item) return []

  return [
    { key: 'items', label: 'Строк', value: item.items_count },
    { key: 'linked', label: 'Привязано', value: item.linked_count },
    { key: 'pending', label: 'Осталось', value: item.pending_count },
  ]
})

onMounted(() => {
  void fetchImports()
})

async function fetchImports(): Promise<void> {
  loading.value = true
  pageError.value = null

  try {
    const response = await api.get('/api/price-imports')
    imports.value = Array.isArray(response.data?.imports) ? response.data.imports : []
  } catch {
    imports.value = []
    pageError.value = 'Не удалось загрузить историю импортов'
  } finally {
    loading.value = false
  }
}

async function fetchImportItems(importId: number): Promise<void> {
  drawer.value.loading = true
  drawer.value.error = null

  try {
    const response = await api.get(`/api/price-imports/${importId}/items`)
    drawer.value.items = Array.isArray(response.data?.items) ? response.data.items : []
  } catch {
    drawer.value.items = []
    drawer.value.error = 'Не удалось загрузить строки импорта'
  } finally {
    drawer.value.loading = false
  }
}

async function openImport(item: ImportListRow): Promise<void> {
  drawer.value.open = true
  drawer.value.importItem = item
  await fetchImportItems(item.id)
}

function closeDrawer(): void {
  drawer.value.open = false
}

function handleDrawerToggle(value: boolean): void {
  drawer.value.open = value
}

function syncDrawerImportMeta(): void {
  const importId = drawer.value.importItem?.id
  if (!importId) return

  const freshItem = imports.value.find((item) => item.id === importId)
  if (freshItem) {
    drawer.value.importItem = freshItem
  }
}

async function handleImportUpdated(importId: number): Promise<void> {
  await fetchImports()
  syncDrawerImportMeta()

  if (drawer.value.open && drawer.value.importItem?.id === importId) {
    await fetchImportItems(importId)
  }
}

function createEmptyDraftRow(): DraftImportRow {
  const row = {
    id: draftRowId,
    name: '',
    value: '',
    unit: '',
  }
  draftRowId += 1
  return row
}

function validateDraftRow(row: DraftImportRow): DraftRowErrors {
  const errors: DraftRowErrors = {
    name: [],
    value: [],
    unit: [],
  }

  if (!row.name.trim()) {
    errors.name.push('Укажите название')
  }

  const numericValue = Number.parseFloat(String(row.value).replace(',', '.'))
  if (!String(row.value).trim()) {
    errors.value.push('Укажите цену')
  } else if (!Number.isFinite(numericValue) || numericValue <= 0) {
    errors.value.push('Цена должна быть больше 0')
  }

  if (!row.unit.trim()) {
    errors.unit.push('Укажите единицу')
  }

  return errors
}

function resetCreateDialog(): void {
  createDialog.value = {
    open: false,
    submitting: false,
    error: null,
    attempted: false,
    rows: [createEmptyDraftRow()],
  }
}

function openCreateDialog(): void {
  createDialog.value.open = true
  createDialog.value.error = null
  createDialog.value.attempted = false

  if (createDialog.value.rows.length === 0) {
    createDialog.value.rows = [createEmptyDraftRow()]
  }
}

function closeCreateDialog(): void {
  resetCreateDialog()
}

function addDraftRow(): void {
  createDialog.value.rows.push(createEmptyDraftRow())
}

function removeDraftRow(index: number): void {
  if (createDialog.value.rows.length <= 1) return
  createDialog.value.rows.splice(index, 1)
}

function updateDraftValue(index: number, rawValue: unknown): void {
  const row = createDialog.value.rows[index]
  if (!row) return
  row.value = typeof rawValue === 'string' ? rawValue : String(rawValue ?? '')
}

async function submitCreateImport(): Promise<void> {
  createDialog.value.attempted = true
  createDialog.value.error = null

  const hasErrors = rowErrors.value.some(
    (errors) => errors.name.length > 0 || errors.value.length > 0 || errors.unit.length > 0
  )

  if (hasErrors) {
    return
  }

  createDialog.value.submitting = true

  try {
    const payload = {
      items: createDialog.value.rows.map((row) => ({
        name: row.name.trim(),
        value: Number.parseFloat(row.value.replace(',', '.')),
        unit: row.unit.trim(),
      })),
    }

    const response = await api.post('/api/price-imports', payload)
    const createdImportId = response.data?.import?.id

    await fetchImports()
    closeCreateDialog()

    const createdImport = imports.value.find((item) => item.id === createdImportId)
    if (createdImport) {
      await openImport(createdImport)
      return
    }

    if (typeof createdImportId === 'number') {
      const fallbackImport: ImportListRow = {
        id: createdImportId,
        type: response.data?.import?.type ?? 'manual',
        status: response.data?.import?.status ?? 'processed',
        created_at: response.data?.import?.created_at ?? null,
        items_count: Array.isArray(response.data?.import?.items) ? response.data.import.items.length : payload.items.length,
        linked_count: 0,
        pending_count: Array.isArray(response.data?.import?.items) ? response.data.import.items.length : payload.items.length,
      }
      await openImport(fallbackImport)
    }
  } catch {
    createDialog.value.error = 'Ошибка при создании импорта'
  } finally {
    createDialog.value.submitting = false
  }
}

function statusLabel(status: ImportStatus): string {
  if (status === 'failed') return 'Ошибка'
  if (status === 'pending') return 'В обработке'
  return 'Готово'
}

function statusColor(status: ImportStatus): string {
  if (status === 'failed') return 'error'
  if (status === 'pending') return 'warning'
  return 'success'
}

</script>

<style scoped>
.price-imports-view {
  display: grid;
  gap: var(--ds-space-20);
}

.price-imports-view__card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.price-imports-view__list {
  display: grid;
}

.price-imports-view__item {
  border: 0;
  border-bottom: 1px solid var(--ds-divider);
  background: transparent;
  padding: var(--ds-space-16) var(--ds-space-20);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--ds-space-12);
  text-align: left;
  transition: background-color 0.18s ease, transform 0.18s ease;
}

.price-imports-view__item:last-child {
  border-bottom: 0;
}

.price-imports-view__item:hover {
  background: var(--ds-surface-hover);
}

.price-imports-view__item:focus-visible {
  outline: 2px solid var(--ds-border-focus);
  outline-offset: -2px;
}

.price-imports-view__item-title {
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.35;
  color: var(--ds-text-primary);
}

.price-imports-view__item-meta {
  margin-top: var(--ds-space-6);
  color: var(--ds-text-secondary);
  font-size: 0.875rem;
  line-height: 1.45;
}

.price-imports-view__item-side {
  display: grid;
  justify-items: end;
  gap: var(--ds-space-10);
}

.price-imports-view__open-link {
  font-size: 14px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
}

.price-imports-view__dialog-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-high) 94%, white 6%);
}

.price-imports-view__dialog-title {
  padding: var(--ds-space-20) var(--ds-space-20) 0;
  font-size: 22px;
  font-weight: 800;
  color: var(--ds-text-primary);
}

.price-imports-view__dialog-body {
  padding-top: var(--ds-space-20);
}

.price-imports-view__row {
  display: grid;
  grid-template-columns: minmax(220px, 1.6fr) minmax(140px, 0.8fr) minmax(120px, 0.6fr) auto;
  gap: var(--ds-space-12);
  align-items: start;
}

.price-imports-view__row-actions {
  display: flex;
  align-items: center;
  min-height: 56px;
}

@media (max-width: 960px) {
  .price-imports-view__item {
    padding: 16px;
    flex-direction: column;
    align-items: flex-start;
  }

  .price-imports-view__item-side {
    justify-items: start;
  }

  .price-imports-view__row {
    grid-template-columns: 1fr;
  }

  .price-imports-view__row-actions {
    min-height: auto;
  }
}
</style>
