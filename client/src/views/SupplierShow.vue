<template>
  <v-container fluid class="pa-0 supplier-page">
    <v-sheet class="pa-4 top-bar" color="surface">
      <div v-if="supplier" class="d-flex flex-wrap align-center ga-3">
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex align-center ga-2 mb-1 flex-wrap">
            <v-btn icon="mdi-arrow-left" variant="text" size="small" :to="{ name: 'suppliers' }" />
            <div class="text-h5 font-weight-medium text-truncate">{{ supplier.name }}</div>
            <v-chip size="small" :color="supplier.is_active ? 'success' : 'grey'" variant="tonal">
              {{ supplier.is_active ? 'Активен' : 'Неактивен' }}
            </v-chip>
          </div>
          <div class="text-caption text-medium-emphasis info-strip">
            <span>Прайс-листов: {{ supplier?.price_lists_count || 0 }}</span>
            <span>Активных версий: {{ supplier?.active_versions_count || 0 }}</span>
            <span>Последнее обновление: {{ supplier?.last_version_at ? formatDateOnly(supplier.last_version_at) : '—' }}</span>
          </div>
          <div v-if="contactLine" class="text-caption text-medium-emphasis mt-1 text-truncate">
            Контакт: {{ contactLine }}
          </div>
        </div>
        <div class="d-flex ga-2 flex-wrap justify-end">
          <v-btn variant="tonal" prepend-icon="mdi-pencil" class="text-none" @click="openEditSupplierDialog">
            Редактировать поставщика
          </v-btn>
          <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openAddSourceDialog">
            Добавить источник цен
          </v-btn>
        </div>
      </div>
      <v-progress-linear v-else indeterminate />
    </v-sheet>

    <v-sheet class="pa-4">
      <div class="d-flex flex-wrap align-center ga-3 mb-3">
        <div>
          <div class="text-h6">Источники цен поставщика</div>
          <div class="text-caption text-medium-emphasis">Все прайсы в одном списке: табличные и документные</div>
        </div>
      </div>

      <v-row class="mb-3" dense>
        <v-col cols="12" md="4">
          <v-btn-toggle v-model="domainFilter" color="primary" density="comfortable" divided class="w-100">
            <v-btn v-for="opt in domainOptions" :key="opt.value" :value="opt.value" size="small" class="flex-grow-1 text-none">
              {{ opt.label }}
            </v-btn>
          </v-btn-toggle>
        </v-col>
        <v-col cols="12" md="4">
          <v-btn-toggle v-model="formatFilter" color="primary" density="comfortable" divided class="w-100">
            <v-btn v-for="opt in formatOptions" :key="opt.value" :value="opt.value" size="small" class="flex-grow-1 text-none">
              {{ opt.label }}
            </v-btn>
          </v-btn-toggle>
        </v-col>
        <v-col cols="12" md="3">
          <v-text-field
            v-model="search"
            label="Поиск по названию, файлу, ссылке"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            variant="outlined"
            clearable
            hide-details
          />
        </v-col>
        <v-col cols="12" md="1" class="d-flex align-center justify-end">
          <v-btn variant="text" size="small" class="text-none" @click="resetFilters">Сбросить</v-btn>
        </v-col>
      </v-row>

      <v-data-table
        :headers="sourceHeaders"
        :items="filteredSources"
        :loading="loadingAll"
        class="elevation-1"
        density="comfortable"
        item-key="row_key"
        item-value="row_key"
        item-selectable="selectable"
        show-select
        v-model="selectedRowKeys"
      >
        <template #loading>
          <v-skeleton-loader type="table" />
          <div class="text-caption text-medium-emphasis pa-2">Загружаем источники цен...</div>
        </template>

        <template #item.domain_label="{ item }">
          <v-chip size="small" variant="tonal" :color="item.domain === 'operations' ? 'blue' : item.domain === 'materials' ? 'green' : 'teal'">
            {{ item.domain_label }}
          </v-chip>
        </template>

        <template #item.format_label="{ item }">
          <v-chip size="small" variant="tonal" :color="item.kind === 'table' ? 'indigo' : 'deep-purple'">
            {{ item.format_label }}
          </v-chip>
        </template>

        <template #item.name="{ item }">
          <div :class="{ 'source-row-highlight-cell': item.row_key === highlightedRowKey }">
            <div class="font-weight-medium">{{ item.name }}</div>
            <div v-if="item.subtitle" class="text-caption text-medium-emphasis">{{ item.subtitle }}</div>
          </div>
        </template>

        <template #item.version_label="{ item }">
          <div class="d-inline-flex align-center">
            <v-btn
              v-if="item.kind === 'table' && (item.raw as PriceList).active_version?.id"
              variant="text"
              size="small"
              class="text-none px-1"
              @click="openActiveVersion(item.raw as PriceList)"
            >
              {{ item.version_label }}
            </v-btn>
            <span v-else class="text-caption">{{ item.version_label }}</span>
          </div>
        </template>

        <template #item.status_label="{ item }">
          <v-chip size="small" :color="statusColor(item.status)" variant="tonal">
            {{ item.status_label }}
          </v-chip>
        </template>

        <template #item.date_label="{ item }">
          <span class="text-caption">{{ item.date_label }}</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex align-center ga-1 flex-wrap justify-end">
            <v-btn
              v-if="item.kind === 'table'"
              variant="tonal"
              size="small"
              class="text-none"
              @click="viewVersions(item.raw as PriceList)"
            >
              Версии
            </v-btn>

            <v-btn
              v-if="item.kind === 'document' && item.status !== 'active'"
              icon
              size="small"
              variant="text"
              title="Активировать"
              @click="activateDoc(item.raw as PriceDocumentRow)"
            >
              <v-icon color="success">mdi-check-circle</v-icon>
            </v-btn>

            <v-btn
              v-if="item.kind === 'document' && item.status === 'active'"
              icon
              size="small"
              variant="text"
              title="В архив"
              @click="archiveDoc(item.raw as PriceDocumentRow)"
            >
              <v-icon color="warning">mdi-archive</v-icon>
            </v-btn>

            <v-btn
              v-if="item.kind === 'document' && (item.raw as PriceDocumentRow).source_type === 'file'"
              icon
              size="small"
              variant="text"
              title="Скачать"
              @click="downloadDoc(item.raw as PriceDocumentRow)"
            >
              <v-icon>mdi-download</v-icon>
            </v-btn>

            <v-btn
              v-if="item.kind === 'table'"
              icon
              size="small"
              variant="text"
              title="Редактировать"
              @click="openEditPriceListDialog(item.raw as PriceList)"
            >
              <v-icon>mdi-pencil</v-icon>
            </v-btn>

            <v-btn
              v-if="item.kind === 'table'"
              icon
              size="small"
              variant="text"
              title="Удалить"
              @click="deletePriceList(item.raw as PriceList)"
            >
              <v-icon color="error">mdi-delete</v-icon>
            </v-btn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center pa-8">
            <div class="text-subtitle-1 mb-2">Пока нет источников цен</div>
            <div class="text-medium-emphasis mb-4">Добавьте Excel-прайс или документ поставщика</div>
            <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openAddSourceDialog">
              Добавить источник цен
            </v-btn>
          </div>
        </template>
      </v-data-table>

      <div class="d-flex flex-wrap align-center ga-2 mt-3">
        <v-chip size="small" variant="tonal">Выбрано: {{ selectedRows.length }}</v-chip>
        <span v-if="bulkLoading" class="text-caption text-medium-emphasis">
          {{ bulkProgress.done }} из {{ bulkProgress.total }} обработано
        </span>
        <v-btn
          size="small"
          color="success"
          variant="tonal"
          class="text-none"
          :disabled="selectedCanActivateCount === 0 || bulkLoading"
          :loading="bulkLoading && bulkAction === 'activate'"
          @click="bulkActivateSelected"
        >
          Активировать ({{ selectedCanActivateCount }})
        </v-btn>
        <v-btn
          size="small"
          color="warning"
          variant="tonal"
          class="text-none"
          :disabled="selectedCanArchiveCount === 0 || bulkLoading"
          :loading="bulkLoading && bulkAction === 'archive'"
          @click="bulkArchiveSelected"
        >
          В архив ({{ selectedCanArchiveCount }})
        </v-btn>
      </div>
    </v-sheet>

    <v-dialog v-model="showAddSourceDialog" max-width="700" persistent>
      <v-card>
        <v-card-title>Добавить источник цен</v-card-title>
        <v-card-text>
          <div class="add-source-steps mb-3">
            <v-chip size="small" variant="tonal">Шаг 1: выберите формат</v-chip>
            <v-chip size="small" variant="tonal">Шаг 2: выберите тип цен</v-chip>
            <v-chip size="small" variant="tonal">Шаг 3: заполните поля и сохраните</v-chip>
          </div>

          <v-row dense>
            <v-col cols="12" md="6">
              <v-select
                v-model="addSourceKind"
                label="Формат источника"
                :items="addKindOptions"
                item-title="label"
                item-value="value"
                density="compact"
                variant="outlined"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-select
                v-model="addSourceDomain"
                label="Тип цен"
                :items="addDomainOptions"
                item-title="label"
                item-value="value"
                density="compact"
                variant="outlined"
              />
            </v-col>
          </v-row>

          <v-divider class="my-3" />

          <div v-if="addSourceKind === 'table'">
            <v-text-field
              v-model="addTableForm.title"
              label="Название прайса *"
              density="compact"
              variant="outlined"
              :rules="[v => !!v || 'Обязательное поле']"
            />
            <v-text-field
              v-model="addTableForm.default_currency"
              label="Валюта"
              density="compact"
              variant="outlined"
              placeholder="RUB"
            />
            <v-textarea
              v-model="addTableForm.description"
              label="Комментарий"
              rows="2"
              density="compact"
              variant="outlined"
            />
          </div>

          <div v-else>
            <v-alert
              v-if="addSourceDomain === 'materials'"
              type="warning"
              variant="tonal"
              class="mb-3"
            >
              Для материалов используйте табличный прайс (Excel/CSV).
            </v-alert>

            <v-btn-toggle v-model="addDocForm.source_type" mandatory color="primary" density="compact" class="mb-3">
              <v-btn value="file" size="small">Загрузить файл</v-btn>
              <v-btn value="url" size="small">Добавить ссылку</v-btn>
            </v-btn-toggle>

            <v-file-input
              v-if="addDocForm.source_type === 'file'"
              v-model="addDocForm.file"
              label="Файл прайса *"
              density="compact"
              variant="outlined"
              accept=".pdf,.xlsx,.xls,.csv,.ods,.doc,.docx"
              show-size
            />

            <v-text-field
              v-else
              v-model="addDocForm.source_url"
              label="Ссылка *"
              density="compact"
              variant="outlined"
              placeholder="https://..."
            />

            <v-text-field
              v-model="addDocForm.title"
              label="Название прайса"
              density="compact"
              variant="outlined"
            />

            <v-text-field
              v-model="addDocForm.effective_date"
              label="Дата актуальности"
              type="date"
              density="compact"
              variant="outlined"
            />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showAddSourceDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!canSaveAddSource" @click="saveAddSource">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="showPriceListDialog" max-width="600px" persistent>
      <v-card>
        <v-card-title>
          <span class="text-h6">{{ editPriceListMode ? 'Редактировать' : 'Создать' }} табличный прайс</span>
        </v-card-title>
        <v-card-text>
          <v-form ref="priceListForm">
            <v-text-field v-model="priceListFormData.title" label="Название *" :rules="[v => !!v || 'Обязательное поле']" required />
            <v-select
              v-model="priceListFormData.type"
              :items="priceListTypeOptions"
              item-title="label"
              item-value="value"
              label="Тип *"
              :rules="[v => !!v || 'Обязательное поле']"
            />
            <v-textarea v-model="priceListFormData.description" label="Описание" rows="2" />
            <v-text-field v-model="priceListFormData.default_currency" label="Валюта по умолчанию" placeholder="RUB" />
            <v-switch v-model="priceListFormData.is_active" label="Активен" color="primary" />
          </v-form>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn text @click="closePriceListDialog">Отмена</v-btn>
          <v-btn color="primary" text @click="savePriceList" :loading="saving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
      <template #actions>
        <v-btn variant="text" @click="snackbar.show = false">Закрыть</v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { suppliersApi, type Supplier } from '@/api/suppliers'
import { priceListsApi, type PriceList, type PriceListCreatePayload, type PriceDocument } from '@/api/priceLists'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'

type DomainFilter = 'all' | 'operations' | 'materials' | 'products'
type SourceFilter = 'all' | 'table' | 'document'
type DocumentPurpose = 'operations' | 'finished_products'
type PriceDocumentRow = PriceDocument & { purpose: DocumentPurpose }

type SourceRow = {
  row_key: string
  kind: 'table' | 'document'
  selectable: boolean
  domain: Exclude<DomainFilter, 'all'>
  domain_label: string
  format_label: string
  name: string
  subtitle: string
  version_label: string
  status: 'active' | 'inactive' | 'archived'
  status_label: string
  date_label: string
  search_blob: string
  raw: PriceList | PriceDocumentRow
}

const route = useRoute()
const router = useRouter()

const loadingSupplier = ref(false)
const loadingPriceLists = ref(false)
const loadingDocs = ref(false)
const saving = ref(false)

const supplier = ref<Supplier | null>(null)
const priceLists = ref<PriceList[]>([])
const priceDocuments = ref<PriceDocumentRow[]>([])

const domainFilter = ref<DomainFilter>('all')
const formatFilter = ref<SourceFilter>('all')
const search = ref('')

const showAddSourceDialog = ref(false)
const addSourceKind = ref<'table' | 'document'>('table')
const addSourceDomain = ref<Exclude<DomainFilter, 'all'>>('products')

const addTableForm = ref({
  title: '',
  default_currency: 'RUB',
  description: '',
})

const addDocForm = ref({
  source_type: 'file' as 'file' | 'url',
  file: null as File | File[] | null,
  source_url: '',
  title: '',
  effective_date: '',
})

const showPriceListDialog = ref(false)
const editPriceListMode = ref(false)
const currentPriceList = ref<PriceList | null>(null)
const selectedRowKeys = ref<string[]>([])
const highlightedRowKey = ref<string | null>(null)
const bulkLoading = ref(false)
const bulkAction = ref<'activate' | 'archive' | null>(null)
const bulkProgress = ref({ done: 0, total: 0 })

const priceListFormData = ref<PriceListCreatePayload>({
  supplier_id: 0,
  title: '',
  type: 'operations',
  description: null,
  default_currency: 'RUB',
  is_active: true,
})

const snackbar = ref({ show: false, message: '', color: 'success' })

const loadingAll = computed(() => loadingSupplier.value || loadingPriceLists.value || loadingDocs.value)

const domainOptions = [
  { value: 'all', label: 'Все' },
  { value: 'operations', label: 'Операции' },
  { value: 'materials', label: 'Материалы' },
  { value: 'products', label: 'Готовые изделия' },
]

const formatOptions = [
  { value: 'all', label: 'Все' },
  { value: 'table', label: 'Табличный прайс' },
  { value: 'document', label: 'Документ' },
]

const addKindOptions = [
  { value: 'table', label: 'Табличный прайс (Excel/CSV)' },
  { value: 'document', label: 'Документ прайса (PDF/ссылка)' },
]

const addDomainOptions = [
  { value: 'operations', label: 'Операции' },
  { value: 'materials', label: 'Материалы' },
  { value: 'products', label: 'Готовые изделия' },
]

const priceListTypeOptions = [
  { value: 'operations', label: 'Операции' },
  { value: 'materials', label: 'Материалы / Готовые изделия' },
]

const sourceHeaders = [
  { title: 'Тип цен', key: 'domain_label', sortable: false, width: '140px' },
  { title: 'Формат', key: 'format_label', sortable: false, width: '190px' },
  { title: 'Название', key: 'name', sortable: false },
  { title: 'Версия', key: 'version_label', sortable: false, width: '120px' },
  { title: 'Статус', key: 'status_label', sortable: false, width: '120px' },
  { title: 'Дата', key: 'date_label', sortable: false, width: '130px' },
  { title: 'Действия', key: 'actions', sortable: false, align: 'end' as const, width: '230px' },
]

const sourceRows = computed<SourceRow[]>(() => {
  const etlRows: SourceRow[] = priceLists.value.map((pl) => {
    const domain = resolvePriceListDomain(pl)
    const active = !!pl.active_version
    const activeDate = pl.active_version?.captured_at || pl.active_version?.effective_date || pl.updated_at
    return {
      row_key: `table_${pl.id}`,
      kind: 'table',
      selectable: false,
      domain,
      domain_label: domainLabel(domain),
      format_label: 'Табличный прайс (Excel/CSV)',
      name: pl.name || pl.title || 'Без названия',
      subtitle: pl.description || '',
      version_label: active ? `v${pl.active_version?.id ?? '—'}` : `${pl.versions_count ?? 0} версий`,
      status: active ? 'active' : 'inactive',
      status_label: active ? 'Активна' : 'Без активной',
      date_label: activeDate ? formatDateOnly(activeDate) : '—',
      search_blob: `${pl.name || ''} ${pl.title || ''} ${pl.description || ''}`.toLowerCase(),
      raw: pl,
    }
  })

  const docRows: SourceRow[] = priceDocuments.value.map((doc) => {
    const domain: Exclude<DomainFilter, 'all'> = doc.purpose === 'operations' ? 'operations' : 'products'
    const name = doc.price_list_name || doc.original_filename || doc.source_url || 'Документ'
    const subtitle = doc.source_type === 'url' ? (doc.source_url || '') : (doc.original_filename || '')
    return {
      row_key: `doc_${doc.version_id}_${doc.purpose}`,
      kind: 'document',
      selectable: true,
      domain,
      domain_label: domainLabel(domain),
      format_label: 'Документ прайса (PDF/ссылка)',
      name,
      subtitle,
      version_label: `v${doc.version_number}`,
      status: doc.status,
      status_label: doc.status === 'active' ? 'Активна' : doc.status === 'archived' ? 'В архиве' : 'Неактивна',
      date_label: doc.captured_at ? formatDateOnly(doc.captured_at) : '—',
      search_blob: `${name} ${subtitle}`.toLowerCase(),
      raw: doc,
    }
  })

  return [...etlRows, ...docRows].sort((a, b) => {
    const ad = parseDateFromLabel(a.date_label)
    const bd = parseDateFromLabel(b.date_label)
    return bd - ad
  })
})

const filteredSources = computed(() => {
  const term = search.value.trim().toLowerCase()
  return sourceRows.value.filter((row) => {
    const byDomain = domainFilter.value === 'all' || row.domain === domainFilter.value
    const byFormat = formatFilter.value === 'all' || (formatFilter.value === 'table' ? row.kind === 'table' : row.kind === 'document')
    const bySearch = !term || row.search_blob.includes(term)
    return byDomain && byFormat && bySearch
  })
})

const selectedRows = computed(() =>
  sourceRows.value.filter((row) => selectedRowKeys.value.includes(row.row_key))
)

const selectedDocumentRows = computed(() =>
  selectedRows.value.filter((row) => row.kind === 'document')
)

const selectedCanActivateCount = computed(() =>
  selectedDocumentRows.value.filter((row) => row.status !== 'active').length
)

const selectedCanArchiveCount = computed(() =>
  selectedDocumentRows.value.filter((row) => row.status === 'active').length
)

const contactLine = computed(() => {
  if (!supplier.value) return ''
  const parts = [supplier.value.contact_person, supplier.value.contact_phone, supplier.value.contact_email, supplier.value.website].filter(Boolean)
  return parts.join(' · ')
})

const canSaveAddSource = computed(() => {
  if (addSourceKind.value === 'table') {
    return !!addTableForm.value.title.trim()
  }

  if (addSourceDomain.value === 'materials') return false

  if (addDocForm.value.source_type === 'file') {
    return !!addDocForm.value.file
  }

  return /^https?:\/\//.test(addDocForm.value.source_url)
})

function resolvePriceListDomain(pl: PriceList): Exclude<DomainFilter, 'all'> {
  const rawDomain = (pl as any).domain as string | undefined
  if (rawDomain === 'operations') return 'operations'
  if (rawDomain === 'materials') return 'materials'
  if (rawDomain === 'finished_products') return 'products'
  return pl.type === 'operations' ? 'operations' : 'materials'
}

function mapUiDomainToApiDomain(value: Exclude<DomainFilter, 'all'>): 'operations' | 'materials' | 'finished_products' {
  if (value === 'operations') return 'operations'
  if (value === 'materials') return 'materials'
  return 'finished_products'
}

function mapUiDomainToPriceListType(value: Exclude<DomainFilter, 'all'>): 'operations' | 'materials' {
  return value === 'operations' ? 'operations' : 'materials'
}

function mapUiDomainToDocPurpose(value: Exclude<DomainFilter, 'all'>): DocumentPurpose {
  return value === 'operations' ? 'operations' : 'finished_products'
}

function domainLabel(value: Exclude<DomainFilter, 'all'>): string {
  if (value === 'operations') return 'Операции'
  if (value === 'materials') return 'Материалы'
  return 'Готовые изделия'
}

function statusColor(status: SourceRow['status']) {
  if (status === 'active') return 'success'
  if (status === 'archived') return 'warning'
  return 'grey'
}

function parseDateFromLabel(label: string): number {
  if (!label || label === '—') return 0
  const [dd, mm, yyyy] = label.split('.')
  if (!dd || !mm || !yyyy) return 0
  return new Date(Number(yyyy), Number(mm) - 1, Number(dd)).getTime()
}

async function fetchSupplier() {
  loadingSupplier.value = true
  try {
    const supplierId = Number(route.params.id)
    supplier.value = await suppliersApi.getById(supplierId)
  } catch (error: any) {
    showSnackbar('Ошибка загрузки поставщика: ' + error.message, 'error')
    router.push({ name: 'suppliers' })
  } finally {
    loadingSupplier.value = false
  }
}

async function fetchPriceLists() {
  if (!supplier.value) return
  loadingPriceLists.value = true
  try {
    const response = await priceListsApi.getAll(supplier.value.id)
    priceLists.value = response.data || []
  } catch (error: any) {
    showSnackbar('Ошибка загрузки табличных прайсов: ' + error.message, 'error')
  } finally {
    loadingPriceLists.value = false
  }
}

async function fetchPriceDocuments() {
  if (!supplier.value) return
  loadingDocs.value = true
  try {
    const supplierId = supplier.value.id
    const [ops, finished] = await Promise.all([
      priceListsApi.getPriceDocuments(supplierId, { purpose: 'operations' }),
      priceListsApi.getPriceDocuments(supplierId, { purpose: 'finished_products' }),
    ])

    priceDocuments.value = [
      ...(ops.data || []).map((item) => ({ ...item, purpose: 'operations' as const })),
      ...(finished.data || []).map((item) => ({ ...item, purpose: 'finished_products' as const })),
    ]
  } catch (error: any) {
    showSnackbar('Ошибка загрузки документов: ' + error.message, 'error')
  } finally {
    loadingDocs.value = false
  }
}

async function refreshData() {
  await Promise.all([fetchPriceLists(), fetchPriceDocuments()])
}

function openAddSourceDialog() {
  addSourceKind.value = formatFilter.value === 'document' ? 'document' : 'table'
  addSourceDomain.value = domainFilter.value === 'all' ? 'products' : domainFilter.value
  addTableForm.value = { title: '', default_currency: 'RUB', description: '' }
  addDocForm.value = { source_type: 'file', file: null, source_url: '', title: '', effective_date: '' }
  showAddSourceDialog.value = true
}

async function saveAddSource() {
  if (!supplier.value || !canSaveAddSource.value) return
  saving.value = true
  let newRowKey: string | null = null
  try {
    if (addSourceKind.value === 'table') {
      const domain = mapUiDomainToApiDomain(addSourceDomain.value)
      const created = await priceListsApi.create({
        supplier_id: supplier.value.id,
        title: addTableForm.value.title.trim(),
        type: mapUiDomainToPriceListType(addSourceDomain.value),
        metadata: domain !== 'operations' ? { domain } : undefined,
        default_currency: addTableForm.value.default_currency || 'RUB',
        description: addTableForm.value.description || null,
        is_active: true,
      })
      newRowKey = `table_${created.id}`
      showSnackbar('Табличный прайс добавлен', 'success')
    } else {
      if (addSourceDomain.value === 'materials') {
        showSnackbar('Для материалов используйте табличный прайс', 'warning')
        return
      }

      const purpose = mapUiDomainToDocPurpose(addSourceDomain.value)
      const payload: any = {
        purpose,
        source_type: addDocForm.value.source_type,
        title: addDocForm.value.title || undefined,
        effective_date: addDocForm.value.effective_date || undefined,
      }

      if (addDocForm.value.source_type === 'file') {
        payload.file = Array.isArray(addDocForm.value.file) ? addDocForm.value.file[0] : addDocForm.value.file
      } else {
        payload.source_url = addDocForm.value.source_url
      }

      const createdDoc = await priceListsApi.uploadPriceDocument(supplier.value.id, payload)
      const createdVersionId = createdDoc?.version?.id
      if (createdVersionId) {
        newRowKey = `doc_${createdVersionId}_${purpose}`
      }
      showSnackbar('Документный прайс добавлен', 'success')
    }

    showAddSourceDialog.value = false
    await fetchSupplier()
    await refreshData()
    if (newRowKey) {
      applyRowHighlight(newRowKey)
    }
  } catch (error: any) {
    showSnackbar('Ошибка сохранения: ' + error.message, 'error')
  } finally {
    saving.value = false
  }
}

function openEditPriceListDialog(priceList: PriceList) {
  editPriceListMode.value = true
  currentPriceList.value = priceList
  priceListFormData.value = {
    supplier_id: priceList.supplier_id,
    title: priceList.name || priceList.title || '',
    type: priceList.type ?? 'operations',
    description: priceList.description ?? null,
    default_currency: priceList.default_currency ?? 'RUB',
    is_active: priceList.is_active,
  }
  showPriceListDialog.value = true
}

function closePriceListDialog() {
  showPriceListDialog.value = false
  editPriceListMode.value = false
  currentPriceList.value = null
}

async function savePriceList() {
  saving.value = true
  try {
    if (editPriceListMode.value && currentPriceList.value) {
      await priceListsApi.update(currentPriceList.value.id, priceListFormData.value)
      showSnackbar('Табличный прайс обновлен', 'success')
    } else {
      await priceListsApi.create(priceListFormData.value)
      showSnackbar('Табличный прайс создан', 'success')
    }
    closePriceListDialog()
    await fetchSupplier()
    await refreshData()
  } catch (error: any) {
    showSnackbar('Ошибка сохранения: ' + error.message, 'error')
  } finally {
    saving.value = false
  }
}

async function deletePriceList(priceList: PriceList) {
  if (!confirm(`Удалить табличный прайс "${priceList.name || priceList.title}"?`)) return

  try {
    await priceListsApi.delete(priceList.id)
    showSnackbar('Табличный прайс удален', 'success')
    await fetchSupplier()
    await refreshData()
  } catch (error: any) {
    showSnackbar('Ошибка удаления: ' + error.message, 'error')
  }
}

function viewVersions(priceList: PriceList) {
  if (!supplier.value) return
  router.push({
    name: 'price-list-versions',
    params: {
      supplierId: supplier.value.id,
      priceListId: priceList.id,
    },
  })
}

function openActiveVersion(priceList: PriceList) {
  if (!supplier.value) return
  const versionId = priceList.active_version?.id
  if (!versionId) {
    viewVersions(priceList)
    return
  }
  router.push({
    name: 'price-list-version-show',
    params: {
      supplierId: supplier.value.id,
      priceListId: priceList.id,
      versionId,
    },
  })
}

function openEditSupplierDialog() {
  router.push({ name: 'suppliers' })
}

function downloadDoc(doc: PriceDocumentRow) {
  window.open(`/api/price-list-versions/${doc.version_id}/download`, '_blank')
}

async function activateDoc(doc: PriceDocumentRow) {
  if (!supplier.value) return
  try {
    await priceListsApi.activatePriceDocument(supplier.value.id, doc.version_id)
    showSnackbar('Версия активирована', 'success')
    await fetchPriceDocuments()
    await fetchSupplier()
  } catch (error: any) {
    showSnackbar('Ошибка активации: ' + error.message, 'error')
  }
}

async function archiveDoc(doc: PriceDocumentRow) {
  if (!supplier.value) return
  try {
    await priceListsApi.archivePriceDocument(supplier.value.id, doc.version_id)
    showSnackbar('Версия отправлена в архив', 'success')
    await fetchPriceDocuments()
    await fetchSupplier()
  } catch (error: any) {
    showSnackbar('Ошибка архивации: ' + error.message, 'error')
  }
}

async function bulkActivateSelected() {
  if (!supplier.value || selectedCanActivateCount.value === 0) return
  if (!confirm(`Активировать выбранные документы (${selectedCanActivateCount.value})?`)) return
  bulkLoading.value = true
  bulkAction.value = 'activate'
  try {
    const rows = selectedDocumentRows.value.filter((row) => row.status !== 'active')
    bulkProgress.value = { done: 0, total: rows.length }
    for (const row of rows) {
      const doc = row.raw as PriceDocumentRow
      await priceListsApi.activatePriceDocument(supplier.value.id, doc.version_id)
      bulkProgress.value.done += 1
    }
    showSnackbar('Выбранные документы активированы', 'success')
    await fetchPriceDocuments()
    await fetchSupplier()
  } catch (error: any) {
    showSnackbar('Ошибка массовой активации: ' + error.message, 'error')
  } finally {
    bulkLoading.value = false
    bulkAction.value = null
    bulkProgress.value = { done: 0, total: 0 }
    selectedRowKeys.value = []
  }
}

async function bulkArchiveSelected() {
  if (!supplier.value || selectedCanArchiveCount.value === 0) return
  if (!confirm(`Отправить в архив выбранные документы (${selectedCanArchiveCount.value})?`)) return
  bulkLoading.value = true
  bulkAction.value = 'archive'
  try {
    const rows = selectedDocumentRows.value.filter((row) => row.status === 'active')
    bulkProgress.value = { done: 0, total: rows.length }
    for (const row of rows) {
      const doc = row.raw as PriceDocumentRow
      await priceListsApi.archivePriceDocument(supplier.value.id, doc.version_id)
      bulkProgress.value.done += 1
    }
    showSnackbar('Выбранные документы отправлены в архив', 'success')
    await fetchPriceDocuments()
    await fetchSupplier()
  } catch (error: any) {
    showSnackbar('Ошибка массовой архивации: ' + error.message, 'error')
  } finally {
    bulkLoading.value = false
    bulkAction.value = null
    bulkProgress.value = { done: 0, total: 0 }
    selectedRowKeys.value = []
  }
}

function applyRowHighlight(rowKey: string) {
  highlightedRowKey.value = rowKey
  setTimeout(() => {
    if (highlightedRowKey.value === rowKey) {
      highlightedRowKey.value = null
    }
  }, 2000)
}

function formatDateOnly(date: string) {
  return format(new Date(date), 'dd.MM.yyyy', { locale: ru })
}

function showSnackbar(message: string, color: string = 'success') {
  snackbar.value = { show: true, message, color }
}

function resetFilters() {
  domainFilter.value = 'all'
  formatFilter.value = 'all'
  search.value = ''
  selectedRowKeys.value = []
}

onMounted(async () => {
  await fetchSupplier()
  await refreshData()
})
</script>

<style scoped>
.top-bar {
  position: sticky;
  top: 0;
  z-index: 2;
}

.info-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.add-source-steps {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.source-row-highlight-cell {
  background-color: rgba(33, 150, 243, 0.12) !important;
  transition: background-color 0.4s ease;
  border-radius: 8px;
}
</style>
