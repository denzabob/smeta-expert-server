<template>
  <v-container fluid class="pa-0">
    <SectionCard
      v-if="showHeader"
      class="fp-specs-header-card"
      :title="headerTitle"
      :subtitle="headerSubtitle"
    >
      <template #header-actions>
        <ButtonGroup>
          <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openCreateDialog">
            Добавить фасад
          </v-btn>
          <v-btn variant="text" prepend-icon="mdi-refresh" class="text-none" :loading="store.loading" @click="loadItems">
            Обновить
          </v-btn>
        </ButtonGroup>
      </template>
    </SectionCard>

    <SectionCard class="fp-specs-card">
      <v-alert density="compact" variant="tonal" type="info" class="mb-4">
        В этом каталоге хранятся ваши фасады. Цены, источники и доказательства открываются из карточки выбранного фасада.
      </v-alert>

      <AppDataTableShell>
        <template #toolbar>
          <TableToolbar>
            <template #search>
          <v-text-field
            v-model="searchQuery"
            label="Поиск по названию, артикулу, коллекции, декору"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            @click:clear="searchQuery = ''"
            @update:model-value="applyFilters"
          />
            </template>
            <template #filters>
          <v-select
            v-model="activeFilter"
            :items="activeFilterItems"
            item-title="label"
            item-value="value"
            label="Статус"
            variant="outlined"
            density="compact"
            hide-details
            class="fp-specs-status-filter"
            @update:model-value="applyFilters"
          />
            </template>
            <template #actions>
          <v-btn variant="text" class="text-none" @click="resetFilters">
            Сбросить фильтры
          </v-btn>
            </template>
          </TableToolbar>
        </template>

      <v-alert
        v-if="store.error"
        type="error"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ store.error }}
      </v-alert>

      <v-data-table
        :headers="headers"
        :items="store.items"
        :loading="store.loading"
        :items-per-page="store.perPage"
        :server-items-length="store.totalItems"
        item-key="id"
        class="fp-specs-table"
        hover
        @click:row="(_event: unknown, payload: any) => openEditDialog(payload.item)"
        @update:page="onPageChange"
      >
        <template #loading>
          <v-skeleton-loader type="table-row@6" class="pa-4" />
        </template>

        <template #item.name="{ item }">
          <div class="py-2">
            <div class="font-weight-medium">{{ item.name }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ compactSpecLine(item) }}
            </div>
          </div>
        </template>

        <template #item.article="{ item }">
          <span>{{ item.article || '—' }}</span>
        </template>

        <template #item.facade_class="{ item }">
          <StatusChip size="small" variant="tonal" color="primary">
            {{ labelFromOptions(item.facade_class, facadeClassOptions) }}
          </StatusChip>
        </template>

        <template #item.base_type="{ item }">
          {{ labelFromOptions(item.base_type, baseTypeOptions) }}
        </template>

        <template #item.covering="{ item }">
          {{ labelFromOptions(item.covering, coveringOptions) }}
        </template>

        <template #item.is_active="{ item }">
          <StatusChip
            :status="item.is_active ? 'active' : 'inactive'"
            :label="item.is_active ? 'Активен' : 'Неактивен'"
          />
        </template>

        <template #item.source_count="{ item }">
          <StatusChip size="small" :status="item.source_count > 0 ? 'active' : 'none'" :color="item.source_count > 0 ? 'success' : 'grey'">
            {{ item.source_count }}
          </StatusChip>
        </template>

        <template #item.aggregation_method="{ item }">
          {{ pricingMethodLabel(item.aggregation_method) }}
        </template>

        <template #item.computed_price_summary="{ item }">
          <div>
            <div class="font-weight-medium">
              <span v-if="item.computed_price_summary.computed_price_per_m2 !== null">
                {{ formatPrice(item.computed_price_summary.computed_price_per_m2) }} ₽/м²
              </span>
              <span v-else class="text-medium-emphasis">—</span>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ computedSummaryLine(item) }}
            </div>
          </div>
        </template>

        <template #item.actions="{ item }">
          <AppRowActions>
            <v-btn
              size="small"
              variant="text"
              class="text-none"
              color="primary"
              @click.stop="showPricingPlaceholder(item)"
            >
              Цены
            </v-btn>
            <v-btn icon size="small" variant="text" color="error" @click.stop="confirmDelete(item)">
              <v-icon>mdi-delete</v-icon>
            </v-btn>
          </AppRowActions>
        </template>

        <template #no-data>
          <AppStateBlock
            icon="mdi-door-sliding-open"
            :title="searchQuery || activeFilter !== 'all' ? 'Ничего не найдено' : 'Спецификаций пока нет'"
            :description="searchQuery || activeFilter !== 'all'
              ? 'Смените фильтры или добавьте новый фасад.'
              : 'Добавьте первый фасад, чтобы настроить цены и доказательства.'"
          >
            <template #actions>
            <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openCreateDialog">
              Добавить фасад
            </v-btn>
            </template>
          </AppStateBlock>
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>

    <FinishedProductSpecificationDialog
      v-model="showDialog"
      :specification="editingItem"
      @saved="handleSaved"
    />

    <v-dialog v-model="showDeleteDialog" max-width="420">
      <v-card class="fp-specs-dialog-card">
        <v-card-title>Удалить фасад?</v-card-title>
        <v-card-text>
          Фасад «{{ deletingItem?.name }}» будет удалён из каталога.
        </v-card-text>
        <AppActionFooter>
          <v-btn @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="store.saving" @click="doDelete">Удалить</v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.visible" :color="snackbar.color" timeout="3000">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'
import type { FinishedProductSpecification } from '@/api/finishedProductSpecifications'
import { useFinishedProductSpecificationsStore } from '@/stores/finishedProductSpecifications'
import FinishedProductSpecificationDialog from './FinishedProductSpecificationDialog.vue'
import {
  baseTypeOptions,
  coveringOptions,
  facadeClassOptions,
  formatPrice,
  labelFromOptions,
  pricingMethodLabel,
} from './finishedProductSpecificationOptions'

withDefaults(defineProps<{
  showHeader?: boolean
  headerTitle?: string
  headerSubtitle?: string
}>(), {
  showHeader: true,
  headerTitle: 'Фасады',
  headerSubtitle: 'Ваш каталог фасадов, цен и подтверждающих документов',
})

const store = useFinishedProductSpecificationsStore()
const router = useRouter()

const searchQuery = ref('')
const activeFilter = ref<'all' | 'active' | 'inactive'>('all')
const showDialog = ref(false)
const showDeleteDialog = ref(false)
const editingItem = ref<FinishedProductSpecification | null>(null)
const deletingItem = ref<FinishedProductSpecification | null>(null)
const snackbar = ref({
  visible: false,
  text: '',
  color: 'success',
})

const activeFilterItems = [
  { value: 'all', label: 'Все' },
  { value: 'active', label: 'Только активные' },
  { value: 'inactive', label: 'Только неактивные' },
]

const headers = [
  { title: 'Спецификация', key: 'name', sortable: false, minWidth: '260px' },
  { title: 'Артикул', key: 'article', sortable: false, width: '120px' },
  { title: 'Класс', key: 'facade_class', sortable: false, width: '120px' },
  { title: 'Основа', key: 'base_type', sortable: false, width: '120px' },
  { title: 'Покрытие', key: 'covering', sortable: false, width: '130px' },
  { title: 'Статус', key: 'is_active', sortable: false, width: '110px' },
  { title: 'Источники', key: 'source_count', sortable: false, width: '100px' },
  { title: 'Агрегация', key: 'aggregation_method', sortable: false, width: '110px' },
  { title: 'Цена', key: 'computed_price_summary', sortable: false, width: '170px' },
  { title: '', key: 'actions', sortable: false, width: '120px', align: 'end' as const },
]

function openCreateDialog() {
  editingItem.value = null
  showDialog.value = true
}

function openEditDialog(item: FinishedProductSpecification) {
  editingItem.value = item
  showDialog.value = true
}

function confirmDelete(item: FinishedProductSpecification) {
  deletingItem.value = item
  showDeleteDialog.value = true
}

async function doDelete() {
  if (!deletingItem.value) return

  try {
    await store.deleteItem(deletingItem.value.id)
    showDeleteDialog.value = false
    deletingItem.value = null
    showSnack('Спецификация удалена', 'success')
    if (!store.items.length && store.currentPage > 1) {
      await store.fetchItems({ page: store.currentPage - 1 })
    }
  } catch {
    showSnack(store.error ?? 'Не удалось удалить спецификацию', 'error')
  }
}

function compactSpecLine(item: FinishedProductSpecification) {
  const parts = [
    labelFromOptions(item.base_type, baseTypeOptions),
    item.thickness_mm ? `${item.thickness_mm} мм` : '— мм',
    labelFromOptions(item.covering, coveringOptions),
    item.collection || null,
    item.decor_label || null,
  ].filter(Boolean)

  return parts.join(' · ')
}

function computedSummaryLine(item: FinishedProductSpecification) {
  const bits = [
    item.aggregation_method ? pricingMethodLabel(item.aggregation_method) : null,
    item.source_count > 0 ? `${item.source_count} источн.` : null,
  ].filter(Boolean)

  if (bits.length > 0) return bits.join(' · ')
  return 'Источники еще не настроены'
}

function showPricingPlaceholder(item: FinishedProductSpecification) {
  router.push({ name: 'finished-product-pricing', params: { id: item.id } })
}

function showSnack(text: string, color = 'success') {
  snackbar.value = {
    visible: true,
    text,
    color,
  }
}

function applyFilters() {
  const params: Record<string, unknown> = {
    search: searchQuery.value || undefined,
    page: 1,
  }

  if (activeFilter.value === 'active') params.is_active = true
  if (activeFilter.value === 'inactive') params.is_active = false

  store.setFilters(params)
  store.fetchItems()
}

function resetFilters() {
  searchQuery.value = ''
  activeFilter.value = 'all'
  store.setFilters({
    search: undefined,
    is_active: undefined,
    page: 1,
  })
  store.fetchItems()
}

function onPageChange(page: number) {
  store.setFilters({ page })
  store.fetchItems()
}

function loadItems() {
  store.fetchItems()
}

function handleSaved(saved: FinishedProductSpecification) {
  const exists = store.items.some((item) => item.id === saved.id)
  if (!exists) {
    store.fetchItems({ page: 1 })
  }

  showSnack(`Спецификация «${saved.name}» сохранена`, 'success')
}

onMounted(() => {
  store.fetchItems()
})
</script>

<style scoped>
.fp-specs-header-card,
.fp-specs-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.fp-specs-card {
  margin-top: var(--ds-space-12);
}

.fp-specs-card :deep(.v-table__wrapper) {
  border-radius: var(--ds-radius-12);
  border: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-lowest), 0.78);
}

.fp-specs-status-filter {
  min-width: 180px;
}

.fp-specs-table {
  min-width: 1180px;
}

.fp-specs-card :deep(thead th) {
  border-bottom: 1px solid var(--ds-divider) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.9) !important;
}

.fp-specs-card :deep(tbody td) {
  vertical-align: middle;
  border-bottom-color: var(--ds-divider) !important;
}

.fp-specs-dialog-card {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16) !important;
  overflow: hidden;
}

.fp-specs-dialog-card :deep(.v-card-actions) {
  padding: 0;
}
</style>
