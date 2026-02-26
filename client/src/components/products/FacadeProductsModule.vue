<template>
  <v-container fluid class="pa-0">
    <!-- Header -->
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
        <v-btn color="secondary" variant="tonal" prepend-icon="mdi-file-upload" class="text-none" @click="showDmsUpload = true">
          Загрузить прайс (PDF/XLSX)
        </v-btn>
        <v-btn variant="outlined" prepend-icon="mdi-table-arrow-down" class="text-none" @click="showEtlImport = true">
          Импортировать прайс (Excel → цены)
        </v-btn>
        <v-btn variant="text" prepend-icon="mdi-refresh" class="text-none" :loading="store.loading" @click="loadFacades">
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <!-- Filters -->
    <v-sheet class="pa-4">
      <v-alert density="compact" variant="tonal" type="info" class="mb-3">
        <div class="d-flex align-center flex-wrap ga-2">
          <v-chip size="small" color="primary" variant="flat">1. Создайте изделие</v-chip>
          <v-chip size="small" color="primary" variant="flat">2. Добавьте котировку</v-chip>
          <v-chip size="small" color="primary" variant="flat">3. Готово</v-chip>
        </div>
      </v-alert>

      <v-row class="mb-2" align="center" dense>
        <v-col cols="12" md="5">
          <v-text-field
            v-model="searchQuery"
            label="Поиск по названию / артикулу"
            prepend-inner-icon="mdi-magnify"
            hide-details clearable
            @click:clear="searchQuery = ''"
            @update:model-value="onFilterChange"
          />
        </v-col>
        <v-col cols="6" md="3">
          <v-select
            v-model="filterClass"
            :items="facadeClassOptions"
            item-title="label" item-value="value"
            label="Класс" clearable hide-details
            @update:model-value="onFilterChange"
          />
        </v-col>
        <v-col cols="6" md="2">
          <v-select
            v-model="sortBy"
            :items="sortOptions"
            item-title="label" item-value="value"
            label="Сортировка" hide-details
            @update:model-value="onFilterChange"
          />
        </v-col>
        <v-col cols="12" md="2" class="d-flex justify-end">
          <v-btn
            variant="text"
            prepend-icon="mdi-tune-variant"
            class="text-none"
            @click="showAdvancedFilters = !showAdvancedFilters"
          >
            Доп. фильтры
            <span v-if="activeAdvancedFiltersCount > 0" class="ml-1">({{ activeAdvancedFiltersCount }})</span>
          </v-btn>
        </v-col>
      </v-row>

      <v-expand-transition>
        <v-row v-show="showAdvancedFilters" class="mb-3" align="center" dense>
          <v-col cols="6" md="3">
            <v-select
              v-model="filterCovering"
              :items="coveringOptions"
              item-title="label" item-value="value"
              label="Покрытие" clearable hide-details
            @update:model-value="onFilterChange"
          />
        </v-col>
          <v-col cols="6" md="2">
          <v-select
            v-model="filterThickness"
            :items="thicknessOptions"
            label="Толщина" clearable hide-details
            @update:model-value="onFilterChange"
          />
        </v-col>
          <v-col cols="6" md="3">
          <v-select
            v-model="filterBaseType"
            :items="baseTypeOptions"
            item-title="label" item-value="value"
            label="Основа" clearable hide-details
            @update:model-value="onFilterChange"
          />
        </v-col>
          <v-col cols="12" md="4" class="d-flex justify-end">
            <v-btn variant="text" class="text-none" @click="resetFilters">
              Сбросить фильтры
            </v-btn>
          </v-col>
        </v-row>
      </v-expand-transition>

      <!-- Table -->
      <v-data-table
        :headers="headers"
        :items="store.facades"
        :loading="store.loading"
        class="elevation-1"
        item-key="id"
        :items-per-page="store.perPage"
        :server-items-length="store.totalItems"
        @update:page="onPageChange"
        @click:row="(_e: any, { item }: any) => openEditDialog(item)"
      >
        <template #item.facade_class="{ item }">
          <v-chip size="small" :color="classColor(item.facade_class)">
            {{ classLabel(item.facade_class) }}
          </v-chip>
        </template>
        <template #item.facade_covering="{ item }">
          {{ coveringLabel(item.facade_covering) }}
        </template>
        <template #item.quotes_count="{ item }">
          <v-chip size="small" :color="(item.quotes_count ?? 0) > 0 ? 'success' : 'grey'">
            {{ item.quotes_count ?? 0 }}
          </v-chip>
        </template>
        <template #item.last_quote_price="{ item }">
          <span v-if="item.last_quote_price" class="font-weight-medium">
            {{ formatPrice(item.last_quote_price) }} ₽/м²
          </span>
          <span v-else class="text-grey">—</span>
        </template>
        <template #item.last_quote_date="{ item }">
          <span v-if="item.last_quote_date">{{ formatDate(item.last_quote_date) }}</span>
          <span v-else class="text-grey">—</span>
        </template>
        <template #item.is_active="{ item }">
          <v-icon :color="item.is_active ? 'success' : 'grey'">
            {{ item.is_active ? 'mdi-check-circle' : 'mdi-close-circle' }}
          </v-icon>
        </template>
        <template #item.actions="{ item }">
          <v-btn icon size="small" variant="text" color="error" @click.stop="confirmDelete(item)">
            <v-icon>mdi-delete</v-icon>
          </v-btn>
        </template>
      </v-data-table>
    </v-sheet>

    <!-- Create/Edit Dialog -->
    <FacadeEditDialog
      v-model="showEditDialog"
      :facade="editingFacade"
      :filter-options="store.filterOptions"
      @saved="onFacadeSaved"
    />

    <!-- DMS Upload Dialog (quick price document upload) -->
    <v-dialog v-model="showDmsUpload" max-width="550" persistent>
      <v-card>
        <v-card-title>Загрузить прайс-документ</v-card-title>
        <v-card-text>
          <v-autocomplete v-model="dmsSupplierId" :items="dmsSuppliers" item-title="name" item-value="id"
            label="Поставщик *" :rules="[v => !!v || 'Обязательное поле']" />
          <v-btn-toggle v-model="dmsSourceType" mandatory color="primary" density="compact" class="mb-3">
            <v-btn value="file" size="small">
              <v-icon start size="small">mdi-file-upload</v-icon>
              Файл
            </v-btn>
            <v-btn value="url" size="small">
              <v-icon start size="small">mdi-link</v-icon>
              URL
            </v-btn>
          </v-btn-toggle>
          <v-file-input v-if="dmsSourceType === 'file'" v-model="dmsFile" label="Файл прайса *"
            accept=".pdf,.xlsx,.xls,.csv,.ods,.doc,.docx" show-size
            prepend-icon="mdi-paperclip" hint="PDF, XLSX, XLS, CSV, ODS — до 10 МБ" persistent-hint />
          <v-text-field v-if="dmsSourceType === 'url'" v-model="dmsUrl" label="URL *"
            placeholder="https://..." prepend-icon="mdi-link" />
          <v-text-field v-model="dmsTitle" label="Название прайс-листа"
            hint="Если не указано — будет создан автоматически" persistent-hint class="mt-2" />
          <v-text-field v-model="dmsEffectiveDate" label="Дата актуальности" type="date" class="mt-2" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDmsUpload = false">Отмена</v-btn>
          <v-btn color="primary" :loading="dmsSaving" :disabled="!canSaveDms" @click="saveDmsUpload">Загрузить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ETL Import Dialog -->
    <PriceImportDialog v-model="showEtlImport" target-type="materials" @imported="loadFacades" />

    <!-- Delete Confirmation -->
    <v-dialog v-model="showDeleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить фасад?</v-card-title>
        <v-card-text>
          Фасад «{{ deletingFacade?.name }}» будет деактивирован (или удалён, если нет ссылок).
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="doDelete" :loading="store.saving">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar" :color="snackbarColor" timeout="3000">
      {{ snackbarText }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useFinishedProductsStore } from '@/stores/finishedProducts'
import FacadeEditDialog from '@/components/FacadeEditDialog.vue'
import PriceImportDialog from '@/components/PriceImportDialog.vue'
import { priceListsApi } from '@/api/priceLists'
import type { FinishedProduct as Facade } from '@/api/finishedProducts'
import api from '@/api/axios'

withDefaults(defineProps<{
  showHeader?: boolean
  headerTitle?: string
  headerSubtitle?: string
}>(), {
  showHeader: true,
  headerTitle: 'Фасады',
  headerSubtitle: 'Готовая продукция — канонические фасады с мультицитированием',
})

const store = useFinishedProductsStore()

// Filters
const searchQuery = ref('')
const filterClass = ref<string | null>(null)
const filterCovering = ref<string | null>(null)
const filterThickness = ref<number | null>(null)
const filterBaseType = ref<string | null>(null)
const sortBy = ref('name')
const showAdvancedFilters = ref(false)

// Dialogs
const showEditDialog = ref(false)
const editingFacade = ref<Facade | null>(null)
const showDeleteDialog = ref(false)
const deletingFacade = ref<Facade | null>(null)

// Snackbar
const snackbar = ref(false)
const snackbarText = ref('')
const snackbarColor = ref('success')

// DMS Upload state
const showDmsUpload = ref(false)
const showEtlImport = ref(false)
const dmsSupplierId = ref<number | null>(null)
const dmsSourceType = ref<'file' | 'url'>('file')
const dmsFile = ref<File | File[] | null>(null)
const dmsUrl = ref('')
const dmsTitle = ref('')
const dmsEffectiveDate = ref('')
const dmsSaving = ref(false)
const dmsSuppliers = ref<any[]>([])

const canSaveDms = computed(() => {
  if (!dmsSupplierId.value) return false
  if (dmsSourceType.value === 'file' && !dmsFile.value) return false
  if (dmsSourceType.value === 'url' && !dmsUrl.value) return false
  return true
})

const headers = [
  { title: 'Название', key: 'name', sortable: false },
  { title: 'Класс', key: 'facade_class', sortable: false, width: '100px' },
  { title: 'Основа', key: 'facade_base_type', sortable: false, width: '80px' },
  { title: 'Толщ.', key: 'facade_thickness_mm', sortable: false, width: '60px' },
  { title: 'Покрытие', key: 'facade_covering', sortable: false, width: '120px' },
  { title: 'Котир.', key: 'quotes_count', sortable: false, width: '70px' },
  { title: 'Цена', key: 'last_quote_price', sortable: false, width: '110px' },
  { title: 'Обновл.', key: 'last_quote_date', sortable: false, width: '100px' },
  { title: '', key: 'actions', sortable: false, width: '50px' },
]

const sortOptions = [
  { value: 'name', label: 'По названию' },
  { value: 'updated_at', label: 'По дате изменения' },
  { value: 'last_quote_date', label: 'По дате котировки' },
]

// Computed filter option lists from store
const facadeClassOptions = computed(() => store.filterOptions?.facade_classes ?? [])
const coveringOptions = computed(() => store.filterOptions?.finish_types ?? [])
const thicknessOptions = computed(() => store.filterOptions?.thickness_options ?? [])
const baseTypeOptions = computed(() => store.filterOptions?.base_materials ?? [])
const activeAdvancedFiltersCount = computed(() =>
  [filterCovering.value, filterThickness.value, filterBaseType.value].filter((v) => v !== null && v !== undefined && v !== '').length
)

// Helpers
function classLabel(val: string | null) {
  if (!val) return '—'
  const found = facadeClassOptions.value.find((o: any) => o.value === val)
  return found ? found.label : val
}

function classColor(val: string | null) {
  const colors: Record<string, string> = {
    STANDARD: 'blue', PREMIUM: 'deep-purple', GEOMETRY: 'teal',
    RADIUS: 'orange', VITRINA: 'cyan', RESHETKA: 'brown',
    AKRIL: 'pink', ALUMINIUM: 'blue-grey', MASSIV: 'green', ECONOMY: 'grey',
  }
  return colors[val ?? ''] ?? 'grey'
}

function coveringLabel(val: string | null) {
  if (!val) return '—'
  const found = coveringOptions.value.find((o: any) => o.value === val)
  return found ? found.label : val
}

function formatPrice(val: number | null) {
  if (!val) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function formatDate(val: string | null) {
  if (!val) return '—'
  const d = new Date(val)
  return d.toLocaleDateString('ru-RU')
}

// Actions
function loadFacades() {
  store.setFilters({
    search: searchQuery.value || undefined,
    facade_class: filterClass.value || undefined,
    covering: filterCovering.value || undefined,
    thickness_mm: filterThickness.value || undefined,
    base_type: filterBaseType.value || undefined,
    sort_by: sortBy.value,
  })
  store.fetchFacades()
}

function onFilterChange() {
  loadFacades()
}

function resetFilters() {
  searchQuery.value = ''
  filterClass.value = null
  filterCovering.value = null
  filterThickness.value = null
  filterBaseType.value = null
  sortBy.value = 'name'
  loadFacades()
}

function onPageChange(page: number) {
  store.setFilters({ page })
  store.fetchFacades({ page })
}

function openCreateDialog() {
  editingFacade.value = null
  showEditDialog.value = true
}

function openEditDialog(facade: Facade) {
  editingFacade.value = facade
  showEditDialog.value = true
}

function onFacadeSaved() {
  showEditDialog.value = false
  loadFacades()
  showSnack('Фасад сохранён', 'success')
}

function confirmDelete(facade: Facade) {
  deletingFacade.value = facade
  showDeleteDialog.value = true
}

async function doDelete() {
  if (!deletingFacade.value) return
  const ok = await store.deleteFacade(deletingFacade.value.id)
  showDeleteDialog.value = false
  if (ok) {
    showSnack('Фасад удалён / деактивирован', 'success')
    loadFacades()
  } else {
    showSnack(store.error ?? 'Ошибка удаления', 'error')
  }
}

function showSnack(text: string, color = 'success') {
  snackbarText.value = text
  snackbarColor.value = color
  snackbar.value = true
}

async function loadDmsSuppliers() {
  try {
    const { data } = await api.get('/api/suppliers', { params: { per_page: 200 } })
    dmsSuppliers.value = Array.isArray(data) ? data : data.data ?? []
  } catch { /* ignore */ }
}

async function saveDmsUpload() {
  if (!dmsSupplierId.value) return
  dmsSaving.value = true
  try {
    await priceListsApi.uploadPriceDocument(dmsSupplierId.value, {
      purpose: 'finished_products',
      source_type: dmsSourceType.value,
      file: dmsSourceType.value === 'file' && dmsFile.value
        ? (Array.isArray(dmsFile.value) ? dmsFile.value[0] : dmsFile.value)
        : undefined,
      source_url: dmsSourceType.value === 'url' ? dmsUrl.value : undefined,
      title: dmsTitle.value || undefined,
      effective_date: dmsEffectiveDate.value || undefined,
    })
    showSnack('Прайс-документ загружен', 'success')
    showDmsUpload.value = false
    // Reset form
    dmsSupplierId.value = null
    dmsFile.value = null
    dmsUrl.value = ''
    dmsTitle.value = ''
    dmsEffectiveDate.value = ''
  } catch (e: any) {
    showSnack(e.response?.data?.message ?? e.message ?? 'Ошибка загрузки', 'error')
  } finally {
    dmsSaving.value = false
  }
}

onMounted(() => {
  store.fetchFilterOptions()
  loadFacades()
  loadDmsSuppliers()
})
</script>
