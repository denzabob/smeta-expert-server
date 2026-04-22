<template>
  <v-container fluid class="pa-0">
    <v-sheet class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">{{ specification?.name || 'Pricing фасада' }}</div>
          <div class="text-medium-emphasis">
            {{ specificationSubtitle }}
          </div>
        </div>
        <v-spacer />
        <v-btn
          color="primary"
          prepend-icon="mdi-plus"
          class="text-none"
          :disabled="!specification"
          @click="openCreateSourceDialog"
        >
          Добавить источник
        </v-btn>
        <v-btn
          variant="text"
          prepend-icon="mdi-refresh"
          class="text-none"
          :loading="loading"
          @click="loadAll"
        >
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <v-sheet class="pa-4">
      <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
        {{ error }}
      </v-alert>

      <v-row dense class="mb-4">
        <v-col cols="12" lg="8">
          <v-row dense>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="pa-4 h-100">
                <div class="text-caption text-medium-emphasis mb-1">Итоговая цена</div>
                <div class="text-h6 font-weight-bold">
                  <span v-if="breakdown.summary.computed_price_per_m2 !== null">
                    {{ formatPrice(breakdown.summary.computed_price_per_m2) }} ₽/м²
                  </span>
                  <span v-else>—</span>
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ pricingMethodLabel(breakdown.summary.method) }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="pa-4 h-100">
                <div class="text-caption text-medium-emphasis mb-1">Источники</div>
                <div class="text-h6 font-weight-bold">{{ sources.length }}</div>
                <div class="text-caption text-medium-emphasis mt-1">
                  В расчете: {{ breakdown.summary.source_count }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="pa-4 h-100">
                <div class="text-caption text-medium-emphasis mb-1">Диапазон</div>
                <div class="text-body-1 font-weight-medium">
                  {{ summaryRangeLabel }}
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  Статус: {{ breakdownStatusLabel }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="pa-4 h-100">
                <div class="text-caption text-medium-emphasis mb-1">Последний пересчет</div>
                <div class="text-body-1 font-weight-medium">
                  {{ formatDateTime(breakdown.summary.computed_at) }}
                </div>
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ specification?.is_active ? 'Спецификация активна' : 'Спецификация выключена' }}
                </div>
              </v-card>
            </v-col>
          </v-row>
        </v-col>

        <v-col cols="12" lg="4">
          <v-card variant="outlined">
            <v-card-title class="text-subtitle-1">Агрегация</v-card-title>
            <v-card-text class="pt-2">
              <v-select
                v-model="profileForm.method"
                :items="finishedProductAggregationMethodItems"
                item-title="label"
                item-value="value"
                label="Метод"
                hide-details="auto"
              >
                <template #item="{ item, props: itemProps }">
                  <v-list-item v-bind="itemProps" :subtitle="item.raw.description" />
                </template>
              </v-select>

              <v-switch
                v-model="profileForm.include_only_active"
                color="primary"
                hide-details
                inset
                label="Учитывать только активные"
                class="mt-3"
              />
              <v-switch
                v-model="profileForm.exclude_stale"
                color="primary"
                hide-details
                inset
                label="Исключать устаревшие"
                class="mt-2"
              />
              <v-text-field
                v-model.number="profileForm.minimum_sources_count"
                type="number"
                min="1"
                label="Минимум источников"
                hint="Оставьте пустым, если порог не нужен."
                persistent-hint
                class="mt-2"
              />

              <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                Итоговая цена и breakdown пересчитываются сразу после сохранения профиля.
              </v-alert>
            </v-card-text>
            <v-card-actions>
              <v-spacer />
              <v-btn color="primary" class="text-none" :loading="savingProfile" @click="saveProfile">
                Сохранить агрегацию
              </v-btn>
            </v-card-actions>
          </v-card>
        </v-col>
      </v-row>

      <v-card variant="outlined">
        <v-card-title class="d-flex align-center">
          <div>
            <div class="text-subtitle-1">Источники цены</div>
            <div class="text-body-2 text-medium-emphasis">
              Новый canonical pricing flow для выбранной фасадной спецификации.
            </div>
          </div>
        </v-card-title>

        <v-card-text class="pt-0">
          <v-data-table
            :headers="headers"
            :items="sources"
            :loading="loading"
            item-key="id"
            hover
          >
            <template #item.supplier="{ item }">
              {{ item.supplier.name || '—' }}
            </template>

            <template #item.source_kind="{ item }">
              <v-chip size="small" variant="tonal">
                {{ pricingSourceKindLabel(item.source_kind) }}
              </v-chip>
            </template>

            <template #item.source_price="{ item }">
              <div>{{ formatPrice(item.source_price) }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.source_unit || '—' }}</div>
            </template>

            <template #item.price_per_m2_normalized="{ item }">
              <span class="font-weight-medium">{{ formatPrice(item.price_per_m2_normalized) }} ₽</span>
            </template>

            <template #item.status="{ item }">
              <v-chip size="small" :color="pricingSourceStatusColor(item.status)" variant="tonal">
                {{ pricingSourceStatusLabel(item.status) }}
              </v-chip>
            </template>

            <template #item.observed_at="{ item }">
              <div>{{ formatDate(item.effective_date) }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ item.captured_at ? `фикс. ${formatDate(item.captured_at)}` : '—' }}
              </div>
            </template>

            <template #item.description="{ item }">
              <div>
                <div v-if="item.article" class="font-weight-medium">{{ item.article }}</div>
                <div v-if="item.description" class="text-caption">{{ item.description }}</div>
                <div v-else-if="item.category" class="text-caption">{{ item.category }}</div>
                <div v-if="item.stale_reason" class="text-caption text-warning">{{ item.stale_reason }}</div>
              </div>
            </template>

            <template #item.evidence_assets_count="{ item }">
              <v-chip size="small" :color="item.has_evidence ? 'success' : 'grey'" variant="tonal">
                {{ item.evidence_assets_count ?? 0 }}
              </v-chip>
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex justify-end ga-1">
                <v-btn size="small" variant="text" class="text-none" @click="openDetails(item)">
                  Детали
                </v-btn>
                <v-btn size="small" variant="text" class="text-none" @click="openEvidence(item)">
                  Evidence
                </v-btn>
                <v-btn size="small" variant="text" class="text-none" @click="openEditSourceDialog(item)">
                  Изм.
                </v-btn>
                <v-btn
                  size="small"
                  variant="text"
                  class="text-none"
                  :color="item.status === 'active' ? 'warning' : 'success'"
                  :loading="actionLoadingId === item.id"
                  @click="toggleSourceStatus(item)"
                >
                  {{ item.status === 'active' ? 'Выключить' : 'Активировать' }}
                </v-btn>
              </div>
            </template>

            <template #no-data>
              <div class="py-10 text-center">
                <v-icon size="40" color="medium-emphasis" class="mb-3">mdi-currency-rub</v-icon>
                <div class="text-subtitle-1 font-weight-medium mb-1">Источники цены пока не настроены</div>
                <div class="text-body-2 text-medium-emphasis mb-4">
                  Добавьте первый price source для новой фасадной спецификации.
                </div>
                <v-btn color="primary" prepend-icon="mdi-plus" class="text-none" @click="openCreateSourceDialog">
                  Добавить источник
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-sheet>

    <FinishedProductPricingSourceDialog
      v-model="showSourceDialog"
      :specification-id="specificationId"
      :source="editingSource"
      @saved="handleSourceSaved"
    />

    <FinishedProductPriceSourceDetailsDialog
      v-model="showDetailsDialog"
      :loading="detailsLoading"
      :error="detailsError"
      :details="sourceDetails"
    />

    <FinishedProductEvidenceManagerDialog
      v-model="showEvidenceDialog"
      :source="evidenceSource"
      @changed="handleEvidenceChanged"
    />

    <v-snackbar v-model="snackbar.visible" :color="snackbar.color" timeout="3000">
      {{ snackbar.text }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  finishedProductPricingApi,
  type FinishedProductAggregationMethod,
  type FinishedProductPriceSource,
  type FinishedProductPriceSourceDetails,
} from '@/api/finishedProductPricing'
import {
  finishedProductSpecificationsApi,
  type FinishedProductSpecification,
} from '@/api/finishedProductSpecifications'
import FinishedProductPricingSourceDialog from './FinishedProductPricingSourceDialog.vue'
import FinishedProductPriceSourceDetailsDialog from './FinishedProductPriceSourceDetailsDialog.vue'
import FinishedProductEvidenceManagerDialog from './FinishedProductEvidenceManagerDialog.vue'
import {
  finishedProductAggregationMethodItems,
  formatDate,
  formatDateTime,
  formatPrice,
  pricingMethodLabel,
  pricingSourceKindLabel,
  pricingSourceStatusColor,
  pricingSourceStatusLabel,
} from './finishedProductPricingOptions'

const props = defineProps<{
  specificationId: number
}>()

const router = useRouter()

const loading = ref(false)
const savingProfile = ref(false)
const error = ref<string | null>(null)
const specification = ref<FinishedProductSpecification | null>(null)
const sources = ref<FinishedProductPriceSource[]>([])
const showSourceDialog = ref(false)
const showDetailsDialog = ref(false)
const showEvidenceDialog = ref(false)
const editingSource = ref<FinishedProductPriceSource | null>(null)
const evidenceSource = ref<FinishedProductPriceSource | null>(null)
const sourceDetails = ref<FinishedProductPriceSourceDetails | null>(null)
const detailsLoading = ref(false)
const detailsError = ref<string | null>(null)
const actionLoadingId = ref<number | null>(null)
const snackbar = ref({
  visible: false,
  text: '',
  color: 'success',
})

const breakdown = reactive({
  summary: {
    computed_price_per_m2: null as number | null,
    method: null as FinishedProductAggregationMethod | null,
    source_count: 0,
    min_price: null as number | null,
    max_price: null as number | null,
    computed_at: null as string | null,
    status: 'none' as 'computed' | 'sources_only' | 'profile_only' | 'none',
  },
  profile: {
    method: 'median' as FinishedProductAggregationMethod | null,
    include_only_active: true,
    exclude_stale: true,
    minimum_sources_count: null as number | null,
  },
})

const profileForm = reactive({
  method: 'median' as FinishedProductAggregationMethod,
  include_only_active: true,
  exclude_stale: true,
  minimum_sources_count: null as number | null,
})

const headers = [
  { title: 'Поставщик', key: 'supplier', sortable: false, width: '160px' },
  { title: 'Источник', key: 'source_kind', sortable: false, width: '130px' },
  { title: 'Цена', key: 'source_price', sortable: false, width: '120px' },
  { title: 'Нормализовано', key: 'price_per_m2_normalized', sortable: false, width: '130px' },
  { title: 'Статус', key: 'status', sortable: false, width: '120px' },
  { title: 'Дата', key: 'observed_at', sortable: false, width: '130px' },
  { title: 'Описание', key: 'description', sortable: false },
  { title: 'Evidence', key: 'evidence_assets_count', sortable: false, width: '90px' },
  { title: '', key: 'actions', sortable: false, width: '300px', align: 'end' as const },
]

const specificationSubtitle = computed(() => {
  if (!specification.value) return 'Загружаем спецификацию...'

  const parts = [
    specification.value.article || null,
    specification.value.base_type || null,
    specification.value.thickness_mm ? `${specification.value.thickness_mm} мм` : null,
    specification.value.covering || null,
    specification.value.collection || null,
  ].filter(Boolean)

  return parts.length > 0 ? parts.join(' · ') : 'Фасадная спецификация'
})

const summaryRangeLabel = computed(() => {
  if (breakdown.summary.min_price === null || breakdown.summary.max_price === null) return '—'
  return `${formatPrice(breakdown.summary.min_price)}–${formatPrice(breakdown.summary.max_price)} ₽`
})

const breakdownStatusLabel = computed(() => {
  switch (breakdown.summary.status) {
    case 'computed':
      return 'цена рассчитана'
    case 'sources_only':
      return 'есть источники, но нет итоговой цены'
    case 'profile_only':
      return 'задан профиль без источников'
    default:
      return 'данных пока нет'
  }
})

function showSnack(text: string, color = 'success') {
  snackbar.value = {
    visible: true,
    text,
    color,
  }
}

function syncProfileForm() {
  profileForm.method = breakdown.profile.method ?? 'median'
  profileForm.include_only_active = breakdown.profile.include_only_active
  profileForm.exclude_stale = breakdown.profile.exclude_stale
  profileForm.minimum_sources_count = breakdown.profile.minimum_sources_count
}

async function loadSpecification() {
  const response = await finishedProductSpecificationsApi.get(props.specificationId)
  specification.value = response.data.data
}

async function loadPricingData() {
  const [breakdownResponse, sourcesResponse] = await Promise.all([
    finishedProductPricingApi.getBreakdown(props.specificationId),
    finishedProductPricingApi.listSources(props.specificationId),
  ])

  Object.assign(breakdown.summary, breakdownResponse.data.summary)
  Object.assign(breakdown.profile, breakdownResponse.data.profile)
  syncProfileForm()

  const breakdownMeta = new Map(
    breakdownResponse.data.sources.map((item) => [
      item.id,
      {
        evidence_assets_count: item.evidence_assets_count ?? 0,
        has_evidence: item.has_evidence ?? false,
      },
    ]),
  )

  sources.value = sourcesResponse.data.sources.map((item) => ({
    ...item,
    evidence_assets_count: breakdownMeta.get(item.id)?.evidence_assets_count ?? 0,
    has_evidence: breakdownMeta.get(item.id)?.has_evidence ?? false,
  }))
}

async function loadAll() {
  loading.value = true
  error.value = null

  try {
    await Promise.all([
      loadSpecification(),
      loadPricingData(),
    ])
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось загрузить pricing для спецификации'
    if (e?.response?.status === 404) {
      router.replace({ name: 'products' })
    }
  } finally {
    loading.value = false
  }
}

function openCreateSourceDialog() {
  editingSource.value = null
  showSourceDialog.value = true
}

function openEditSourceDialog(source: FinishedProductPriceSource) {
  editingSource.value = source
  showSourceDialog.value = true
}

async function handleSourceSaved() {
  await loadPricingData()
  showSnack(editingSource.value ? 'Источник цены обновлен' : 'Источник цены создан', 'success')
}

async function saveProfile() {
  savingProfile.value = true

  try {
    await finishedProductPricingApi.updateAggregationProfile(props.specificationId, {
      method: profileForm.method,
      include_only_active: profileForm.include_only_active,
      exclude_stale: profileForm.exclude_stale,
      minimum_sources_count: profileForm.minimum_sources_count || null,
    })

    await loadPricingData()
    showSnack('Профиль агрегации сохранен', 'success')
  } catch (e: any) {
    showSnack(e?.response?.data?.message ?? 'Не удалось сохранить агрегацию', 'error')
  } finally {
    savingProfile.value = false
  }
}

async function toggleSourceStatus(source: FinishedProductPriceSource) {
  actionLoadingId.value = source.id

  try {
    if (source.status === 'active') {
      await finishedProductPricingApi.deactivateSource(source.id)
      showSnack('Источник деактивирован', 'success')
    } else {
      await finishedProductPricingApi.activateSource(source.id)
      showSnack('Источник активирован', 'success')
    }

    await loadPricingData()
  } catch (e: any) {
    showSnack(e?.response?.data?.message ?? 'Не удалось изменить статус источника', 'error')
  } finally {
    actionLoadingId.value = null
  }
}

async function openDetails(source: FinishedProductPriceSource) {
  showDetailsDialog.value = true
  detailsLoading.value = true
  detailsError.value = null
  sourceDetails.value = null

  try {
    const response = await finishedProductPricingApi.getSourceDetails(source.id)
    sourceDetails.value = response.data
  } catch (e: any) {
    detailsError.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось загрузить детали источника'
  } finally {
    detailsLoading.value = false
  }
}

function openEvidence(source: FinishedProductPriceSource) {
  evidenceSource.value = source
  showEvidenceDialog.value = true
}

async function handleEvidenceChanged() {
  await loadPricingData()
  if (showDetailsDialog.value && evidenceSource.value) {
    openDetails(evidenceSource.value)
  }
  showSnack('Evidence assets обновлены', 'success')
}

watch(
  () => props.specificationId,
  () => {
    loadAll()
  },
)

onMounted(() => {
  loadAll()
})
</script>
