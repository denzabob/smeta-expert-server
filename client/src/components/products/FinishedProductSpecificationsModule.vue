<template>
  <v-container fluid class="pa-0">
    <v-sheet v-if="showHeader" class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">{{ headerTitle }}</div>
          <div class="text-medium-emphasis">{{ headerSubtitle }}</div>
        </div>
        <v-spacer />
        <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openCreateDialog">
          Добавить фасад
        </v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" class="text-none" :loading="store.loading" @click="loadItems">
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <v-sheet class="pa-4">
      <v-alert density="compact" variant="tonal" type="info" class="mb-4">
        Новый основной каталог фасадов работает поверх `finished_product_specifications`.
        Управление pricing sources теперь открывается отдельным canonical flow для выбранной спецификации.
      </v-alert>

      <v-row dense class="mb-4">
        <v-col cols="12" md="6">
          <v-text-field
            v-model="searchQuery"
            label="Поиск по названию, артикулу, коллекции, декору"
            prepend-inner-icon="mdi-magnify"
            hide-details
            clearable
            @click:clear="searchQuery = ''"
            @update:model-value="applyFilters"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="activeFilter"
            :items="activeFilterItems"
            item-title="label"
            item-value="value"
            label="Статус"
            hide-details
            @update:model-value="applyFilters"
          />
        </v-col>
        <v-col cols="12" md="3" class="d-flex justify-end align-center">
          <v-btn variant="text" class="text-none" @click="resetFilters">
            Сбросить фильтры
          </v-btn>
        </v-col>
      </v-row>

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
        class="elevation-1"
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
          <v-chip size="small" variant="tonal" color="primary">
            {{ labelFromOptions(item.facade_class, facadeClassOptions) }}
          </v-chip>
        </template>

        <template #item.base_type="{ item }">
          {{ labelFromOptions(item.base_type, baseTypeOptions) }}
        </template>

        <template #item.covering="{ item }">
          {{ labelFromOptions(item.covering, coveringOptions) }}
        </template>

        <template #item.is_active="{ item }">
          <v-chip size="small" :color="item.is_active ? 'success' : 'grey'" variant="tonal">
            {{ item.is_active ? 'Активен' : 'Неактивен' }}
          </v-chip>
        </template>

        <template #item.source_count="{ item }">
          <v-chip size="small" :color="item.source_count > 0 ? 'success' : 'grey'" variant="tonal">
            {{ item.source_count }}
          </v-chip>
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
          <div class="d-flex justify-end ga-1">
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
          </div>
        </template>

        <template #no-data>
          <div class="py-10 text-center">
            <v-icon size="40" color="medium-emphasis" class="mb-3">mdi-door-sliding-open</v-icon>
            <div class="text-subtitle-1 font-weight-medium mb-1">
              {{ searchQuery || activeFilter !== 'all' ? 'Ничего не найдено' : 'Спецификаций пока нет' }}
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              {{ searchQuery || activeFilter !== 'all'
                ? 'Смените фильтры или создайте новую спецификацию фасада.'
                : 'Создайте первую фасадную спецификацию в новом каталоге finished-product.' }}
            </div>
            <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openCreateDialog">
              Добавить фасад
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </v-sheet>

    <FinishedProductSpecificationDialog
      v-model="showDialog"
      :specification="editingItem"
      @saved="handleSaved"
    />

    <v-dialog v-model="showDeleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить фасад?</v-card-title>
        <v-card-text>
          Спецификация «{{ deletingItem?.name }}» будет удалена из нового каталога.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="store.saving" @click="doDelete">Удалить</v-btn>
        </v-card-actions>
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
  headerSubtitle: 'Новый каталог спецификаций finished-product на базе spec-rooted API',
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
    item.source_count > 0 ? `${item.source_count} ист.` : null,
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
