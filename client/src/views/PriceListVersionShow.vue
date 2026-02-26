<template>
  <v-container fluid class="pa-0">
    <!-- Header -->
    <v-sheet class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div class="flex-grow-1">
          <div class="d-flex align-center ga-2 mb-1">
            <v-btn
              icon="mdi-arrow-left"
              variant="text"
              size="small"
              @click="goBack"
            />
            <div class="text-h5 font-weight-medium">Аудит версии прайс-листа</div>
          </div>
          <div v-if="version" class="text-medium-emphasis ml-10">
            {{ formatDate(version.effective_date || version.created_at) }}
            <v-chip
              size="small"
              :color="getStatusColor(version.status)"
              variant="tonal"
              class="ml-2"
            >
              {{ getStatusLabel(version.status) }}
            </v-chip>
          </div>
        </div>
        <v-btn
          variant="text"
          prepend-icon="mdi-refresh"
          class="text-none"
          :loading="loading"
          @click="fetchItems"
        >
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <!-- Version Metadata -->
    <v-sheet v-if="version" class="pa-4">
      <v-row dense>
        <v-col cols="12" md="4">
          <v-card class="h-100">
            <v-card-text>
              <div class="text-caption text-medium-emphasis">Статус</div>
              <v-chip
                :color="getStatusColor(version.status)"
                variant="tonal"
                class="mt-1"
              >
                {{ getStatusLabel(version.status) }}
              </v-chip>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="4">
          <v-card class="h-100">
            <v-card-text>
              <div class="text-caption text-medium-emphasis">Источник</div>
              <div class="mt-1">
                <v-icon size="small" class="mr-1">{{ getSourceIcon(version.source_type) }}</v-icon>
                {{ getSourceLabel(version.source_type) }}
              </div>
              <div v-if="getDisplayFilename(version)" class="mt-2">
                <v-btn
                  variant="tonal"
                  size="small"
                  color="primary"
                  prepend-icon="mdi-download"
                  class="text-none"
                  @click="downloadFile"
                >
                  {{ getDisplayFilename(version) }}
                </v-btn>
              </div>
              <div v-if="version.size_bytes" class="text-caption text-medium-emphasis mt-2">
                {{ formatBytes(version.size_bytes) }}
              </div>
            </v-card-text>
          </v-card>
        </v-col>
        <v-col cols="12" md="4">
          <v-card class="h-100">
            <v-card-text>
              <div class="text-caption text-medium-emphasis">Всего позиций</div>
              <div class="text-h5 mt-1">{{ version.items_count || 0 }}</div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>
    </v-sheet>

    <!-- Items Table with Filters -->
    <v-sheet class="pa-4">
      <div class="d-flex justify-space-between align-center mb-3">
        <div class="text-h6">Позиции прайс-листа</div>
      </div>

      <v-row class="mb-3" align="center" dense>
        <v-col cols="12" md="4">
          <v-text-field
            v-model="search"
            label="Поиск по названию или артикулу"
            prepend-inner-icon="mdi-magnify"
            hide-details
            clearable
            @click:clear="search = ''"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="priceTypeFilter"
            :items="priceTypeOptions"
            item-title="label"
            item-value="value"
            label="Тип позиции"
            clearable
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="linkedFilter"
            :items="linkedFilterOptions"
            item-title="label"
            item-value="value"
            label="Привязка"
            hide-details
          />
        </v-col>
      </v-row>

      <v-data-table
        :headers="itemHeaders"
        :items="items"
        :loading="loading"
        class="elevation-1"
        item-key="id"
        density="comfortable"
      >
        <!-- Тип -->
        <template #item.price_type="{ item }">
          <v-chip
            size="small"
            :color="item.price_type === 'operation' ? 'blue' : 'green'"
            variant="tonal"
          >
            {{ item.price_type === 'operation' ? 'Работа' : 'Материал' }}
          </v-chip>
        </template>

        <!-- Название -->
        <template #item.title="{ item }">
          <div>
            <div class="font-weight-medium">{{ item.title }}</div>
            <div v-if="item.article" class="text-caption text-medium-emphasis">
              Арт: {{ item.article }}
            </div>
            <div v-if="item.operation_name && item.title !== item.operation_name" class="text-caption text-success">
              → {{ item.operation_name }}
            </div>
          </div>
        </template>

        <!-- Привязка -->
        <template #item.linked="{ item }">
          <v-chip
            size="small"
            :color="isLinked(item) ? 'success' : 'warning'"
            variant="tonal"
          >
            {{ isLinked(item) ? 'Привязана' : 'Не привязана' }}
          </v-chip>
        </template>

        <!-- Цены -->
        <template #item.prices="{ item }">
          <div>
            <div class="font-weight-medium">
              {{ formatPrice(item.price_supplier, item.currency) }}
            </div>
            <div v-if="item.price_buy" class="text-caption text-medium-emphasis">
              Закупка: {{ formatPrice(item.price_buy, item.currency) }}
            </div>
          </div>
        </template>

        <!-- Единица измерения -->
        <template #item.unit="{ item }">
          {{ item.unit || '—' }}
        </template>

        <!-- Действия -->
        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn
              v-if="!isLinked(item) && item.price_type === 'operation'"
              size="small"
              variant="tonal"
              color="primary"
              @click="openLinkDialog(item)"
            >
              Привязать
            </v-btn>
            <v-btn
              v-else-if="isLinked(item) && item.price_type === 'operation'"
              size="x-small"
              variant="text"
              color="error"
              icon="mdi-link-off"
              @click="unlinkItem(item)"
            />
          </div>
        </template>
      </v-data-table>
    </v-sheet>

    <!-- Link Operation Dialog -->
    <v-dialog v-model="linkDialog.show" max-width="600" persistent>
      <v-card>
        <v-card-title>Привязка к базовой операции</v-card-title>
        <v-card-text>
          <div class="mb-3 text-medium-emphasis">
            Позиция прайса: <strong>{{ linkDialog.item?.title }}</strong>
          </div>
          <v-autocomplete
            v-model="linkDialog.selectedOperationId"
            :items="linkDialog.operationResults"
            :loading="linkDialog.searching"
            item-title="name"
            item-value="id"
            label="Поиск базовой операции"
            placeholder="Введите название операции..."
            no-data-text="Ничего не найдено"
            clearable
            @update:search="searchOperations"
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
          <v-btn variant="text" @click="closeLinkDialog">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="linkDialog.saving"
            :disabled="!linkDialog.selectedOperationId"
            @click="confirmLink"
          >
            Привязать
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
      <template v-slot:actions>
        <v-btn variant="text" @click="snackbar.show = false">Закрыть</v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { 
  priceListsApi, 
  type PriceListVersion, 
  type PriceListVersionItem 
} from '@/api/priceLists'
import api from '@/api/axios'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'

const route = useRoute()
const router = useRouter()

// State
const loading = ref(false)
const version = ref<PriceListVersion | null>(null)
const items = ref<PriceListVersionItem[]>([])

// Filters
const search = ref('')
const priceTypeFilter = ref<'operation' | 'material' | null>(null)
const linkedFilter = ref<'all' | 'linked' | 'unlinked'>('all')

const snackbar = ref({
  show: false,
  message: '',
  color: 'success'
})

// Table headers
const itemHeaders = [
  { title: 'Тип', key: 'price_type', sortable: true, width: '120px' },
  { title: 'Название', key: 'title', sortable: true },
  { title: 'Привязка', key: 'linked', sortable: false, width: '130px' },
  { title: 'Цена в руб.', key: 'prices', sortable: false, width: '200px' },
  { title: 'Ед. изм.', key: 'unit', sortable: true, width: '100px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '140px' },
]

// Filter options
const priceTypeOptions = [
  { label: 'Работы', value: 'operation' },
  { label: 'Материалы', value: 'material' }
]
const linkedFilterOptions = [
  { label: 'Все', value: 'all' },
  { label: 'Привязанные', value: 'linked' },
  { label: 'Не привязанные', value: 'unlinked' },
]

// Methods
const fetchVersion = async () => {
  try {
    const versionId = Number(route.params.versionId)
    version.value = await priceListsApi.getVersionById(versionId)
  } catch (error: any) {
    showSnackbar('Ошибка загрузки версии: ' + error.message, 'error')
  }
}

const fetchItems = async () => {
  loading.value = true
  try {
    const versionId = Number(route.params.versionId)
    const response = await priceListsApi.getVersionItems(versionId, {
      q: search.value || undefined,
      price_type: priceTypeFilter.value || undefined,
      unlinked_only: linkedFilter.value === 'unlinked' ? true : undefined,
      linked_only: linkedFilter.value === 'linked' ? true : undefined
    })
    items.value = response.data || []
  } catch (error: any) {
    showSnackbar('Ошибка загрузки позиций: ' + error.message, 'error')
  } finally {
    loading.value = false
  }
}

const goBack = () => {
  router.push({
    name: 'price-list-versions',
    params: {
      supplierId: route.params.supplierId,
      priceListId: route.params.priceListId
    }
  })
}

const isLinked = (item: PriceListVersionItem) => {
  return !!(item.operation_id || item.material_id)
}

const hasFile = (ver: PriceListVersion | null) => {
  if (!ver) return false
  return !!(ver.file_path || ver.source_file_path || ver.original_filename)
}

const getDisplayFilename = (ver: PriceListVersion | null) => {
  if (!ver) return ''
  if (ver.original_filename) return ver.original_filename
  if (ver.source_file_path) {
    const parts = ver.source_file_path.split(/[/\\]/)
    return parts[parts.length - 1]
  }
  if (ver.file_path) {
    const parts = ver.file_path.split(/[/\\]/)
    return parts[parts.length - 1]
  }
  return ''
}

const downloadFile = async () => {
  if (!version.value) return
  try {
    const blob = await priceListsApi.downloadVersion(version.value.id)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = version.value.original_filename || `price_list_v${version.value.id}`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
    showSnackbar('Файл загружен успешно', 'success')
  } catch (error: any) {
    showSnackbar('Ошибка загрузки файла: ' + error.message, 'error')
  }
}

const getStatusColor = (status: string) => {
  switch (status) {
    case 'active': return 'success'
    case 'inactive': return 'warning'
    case 'archived': return 'grey'
    default: return 'grey'
  }
}

const getStatusLabel = (status: string) => {
  switch (status) {
    case 'active': return 'Активна'
    case 'inactive': return 'Неактивна'
    case 'archived': return 'Архив'
    default: return status
  }
}

const getSourceIcon = (sourceType?: string | null) => {
  if (!sourceType) return 'mdi-help'
  switch (sourceType) {
    case 'file': return 'mdi-file'
    case 'manual': return 'mdi-pencil'
    case 'url': return 'mdi-web'
    default: return 'mdi-help'
  }
}

const getSourceLabel = (sourceType?: string | null) => {
  if (!sourceType) return '—'
  switch (sourceType) {
    case 'file': return 'Файл'
    case 'manual': return 'Ручной ввод'
    case 'url': return 'URL'
    default: return sourceType
  }
}

const formatBytes = (bytes: number) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatPrice = (price: number | string | null | undefined, _currency?: string) => {
  if (price === null || price === undefined) {
    return '—'
  }
  const numPrice = typeof price === 'string' ? parseFloat(price) : price
  if (isNaN(numPrice)) {
    return '—'
  }
  return numPrice.toFixed(2)
}

const formatDate = (date: string) => {
  return format(new Date(date), 'dd MMM yyyy HH:mm', { locale: ru })
}

const showSnackbar = (message: string, color: string = 'success') => {
  snackbar.value = { show: true, message, color }
}

// ============ Link Dialog ============

interface OperationSearchResult {
  id: number
  name: string
  unit: string
  category: string
}

const linkDialog = ref({
  show: false,
  item: null as PriceListVersionItem | null,
  selectedOperationId: null as number | null,
  operationResults: [] as OperationSearchResult[],
  searching: false,
  saving: false,
})

let searchDebounce: ReturnType<typeof setTimeout> | null = null

const openLinkDialog = (item: PriceListVersionItem) => {
  linkDialog.value.item = item
  linkDialog.value.selectedOperationId = null
  linkDialog.value.operationResults = []
  linkDialog.value.show = true

  // Pre-search with item title
  if (item.title) {
    doSearchOperations(item.title)
  }
}

const closeLinkDialog = () => {
  linkDialog.value.show = false
  linkDialog.value.item = null
  linkDialog.value.selectedOperationId = null
  linkDialog.value.operationResults = []
}

const searchOperations = (query: string | null) => {
  if (searchDebounce) clearTimeout(searchDebounce)
  if (!query || query.length < 2) return
  searchDebounce = setTimeout(() => {
    doSearchOperations(query)
  }, 300)
}

const doSearchOperations = async (query: string) => {
  linkDialog.value.searching = true
  try {
    const { data } = await api.get('/api/operations/search', {
      params: { q: query, limit: 100 }
    })
    linkDialog.value.operationResults = data
  } catch (error) {
    linkDialog.value.operationResults = []
  } finally {
    linkDialog.value.searching = false
  }
}

const confirmLink = async () => {
  if (!linkDialog.value.item || !linkDialog.value.selectedOperationId) return
  linkDialog.value.saving = true
  try {
    await priceListsApi.linkOperationPrice(
      linkDialog.value.item.id,
      linkDialog.value.selectedOperationId
    )
    showSnackbar('Операция привязана', 'success')
    closeLinkDialog()
    await fetchItems()
  } catch (error: any) {
    const responseData = error?.response?.data
    if (error?.response?.status === 422 && responseData?.can_force_replace) {
      const confirmReplace = confirm(
        (responseData?.message || 'Эта операция уже привязана к другой строке.') +
        '\n\nПереназначить привязку на текущую строку?'
      )

      if (confirmReplace) {
        try {
          await priceListsApi.linkOperationPrice(
            linkDialog.value.item.id,
            linkDialog.value.selectedOperationId,
            true
          )
          showSnackbar('Привязка переназначена', 'success')
          closeLinkDialog()
          await fetchItems()
          return
        } catch (replaceError: any) {
          showSnackbar('Ошибка переназначения: ' + (replaceError?.response?.data?.message || replaceError.message), 'error')
          return
        }
      }
    }

    showSnackbar('Ошибка привязки: ' + (responseData?.message || error.message), 'error')
  } finally {
    linkDialog.value.saving = false
  }
}

const unlinkItem = async (item: PriceListVersionItem) => {
  try {
    await priceListsApi.unlinkOperationPrice(item.id)
    showSnackbar('Привязка удалена', 'success')
    await fetchItems()
  } catch (error: any) {
    showSnackbar('Ошибка: ' + (error?.response?.data?.message || error.message), 'error')
  }
}

// Watch filters for auto-apply with debounce for search
let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(search, () => {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(() => {
    fetchItems()
  }, 300)
})

// Instant apply for non-search filters
watch([priceTypeFilter, linkedFilter], () => {
  fetchItems()
})

// Lifecycle
onMounted(async () => {
  await fetchVersion()
  await fetchItems()
})
</script>
