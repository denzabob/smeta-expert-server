<template>
  <v-container fluid class="pa-0 fp-pricing">
    <v-sheet class="fp-pricing-hero">
      <div class="fp-pricing-hero__content">
        <div>
          <div class="fp-pricing-hero__title">{{ specification?.name || 'Цены фасада' }}</div>
          <div class="fp-pricing-hero__subtitle">
            {{ specificationSubtitle }}
          </div>
        </div>
        <div class="fp-pricing-hero__actions">
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
            variant="tonal"
            prepend-icon="mdi-refresh"
            class="text-none"
            :loading="loading"
            @click="loadAll"
          >
            Обновить
          </v-btn>
        </div>
      </div>
    </v-sheet>

    <v-sheet class="fp-pricing-body">
      <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
        {{ error }}
      </v-alert>

      <v-row dense class="fp-pricing-layout">
        <v-col cols="12" lg="8">
          <v-row dense>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="fp-pricing-metric h-100">
                <div class="fp-pricing-metric__label">Итоговая цена</div>
                <div class="fp-pricing-metric__value">
                  <span v-if="breakdown.summary.computed_price_per_m2 !== null">
                    {{ formatPrice(breakdown.summary.computed_price_per_m2) }} ₽/м²
                  </span>
                  <span v-else>—</span>
                </div>
                <div class="fp-pricing-metric__hint">
                  {{ pricingMethodLabel(breakdown.summary.method) }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="fp-pricing-metric h-100">
                <div class="fp-pricing-metric__label">Источники</div>
                <div class="fp-pricing-metric__value">{{ sources.length }}</div>
                <div class="fp-pricing-metric__hint">
                  Использовано: {{ breakdown.summary.source_count }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="fp-pricing-metric h-100">
                <div class="fp-pricing-metric__label">Диапазон</div>
                <div class="fp-pricing-metric__body">
                  {{ summaryRangeLabel }}
                </div>
                <div class="fp-pricing-metric__hint">
                  Статус: {{ breakdownStatusLabel }}
                </div>
              </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-card variant="outlined" class="fp-pricing-metric h-100">
                <div class="fp-pricing-metric__label">Последний расчёт</div>
                <div class="fp-pricing-metric__body">
                  {{ formatDateTime(breakdown.summary.computed_at) }}
                </div>
                <div class="fp-pricing-metric__hint">
                  {{ specification?.is_active ? 'Спецификация активна' : 'Спецификация выключена' }}
                </div>
              </v-card>
            </v-col>
          </v-row>
        </v-col>

        <v-col cols="12" lg="4">
          <v-card variant="outlined" class="fp-pricing-profile-card">
            <v-card-title class="fp-pricing-card-title">
              Как считать итоговую цену
              <v-spacer />
              <v-progress-circular
                v-if="savingProfile"
                indeterminate
                size="18"
                width="2"
                color="primary"
              />
            </v-card-title>
            <v-card-text class="fp-pricing-profile-form">
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
              />
              <v-switch
                v-model="profileForm.exclude_stale"
                color="primary"
                hide-details
                inset
                label="Исключать устаревшие"
              />
              <v-text-field
                v-model.number="profileForm.minimum_sources_count"
                type="number"
                min="1"
                label="Минимум источников"
                hint="Оставьте пустым, если порог не нужен."
                persistent-hint
              />

              <v-alert type="info" variant="tonal" density="compact">
                Цена пересчитывается автоматически.
              </v-alert>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <v-card variant="outlined" class="fp-pricing-sources-card">
        <v-card-title class="fp-pricing-sources-card__title">
          <div>
            <div class="text-subtitle-1">Источники цены</div>
            <div class="text-body-2 text-medium-emphasis">
              Добавьте цены поставщиков, документы, ссылки и скриншоты. Итоговая цена считается по выбранному способу.
            </div>
          </div>
        </v-card-title>

        <v-card-text class="fp-pricing-sources-card__content">
          <v-data-table
            :headers="headers"
            :items="sources"
            :loading="loading"
            item-key="id"
            hover
            class="fp-pricing-table"
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
              <div class="d-flex flex-column align-start ga-1">
                <v-chip size="small" :color="item.has_evidence ? 'success' : 'warning'" variant="tonal">
                  {{ item.has_evidence ? `${item.evidence_assets_count ?? 0}` : 'Нет' }}
                </v-chip>
                <div v-if="!item.has_evidence" class="text-caption text-warning">
                  Рекомендуется добавить файл, скриншот или ссылку.
                </div>
              </div>
            </template>

            <template #item.actions="{ item }">
              <div class="fp-pricing-row-actions">
                <v-tooltip text="Детали" location="top">
                  <template #activator="{ props: tooltipProps }">
                    <v-btn
                      v-bind="tooltipProps"
                      icon="mdi-information-outline"
                      size="small"
                      variant="text"
                      aria-label="Детали источника"
                      @click="openDetails(item)"
                    />
                  </template>
                </v-tooltip>
                <v-tooltip text="Доказательства" location="top">
                  <template #activator="{ props: tooltipProps }">
                    <v-btn
                      v-bind="tooltipProps"
                      icon="mdi-paperclip"
                      size="small"
                      variant="text"
                      aria-label="Доказательства источника"
                      @click="openEvidence(item)"
                    />
                  </template>
                </v-tooltip>
                <v-tooltip text="Изменить" location="top">
                  <template #activator="{ props: tooltipProps }">
                    <v-btn
                      v-bind="tooltipProps"
                      icon="mdi-pencil"
                      size="small"
                      variant="text"
                      aria-label="Изменить источник"
                      @click="openEditSourceDialog(item)"
                    />
                  </template>
                </v-tooltip>
                <v-tooltip :text="item.status === 'active' ? 'Выключить' : 'Активировать'" location="top">
                  <template #activator="{ props: tooltipProps }">
                    <v-btn
                      v-bind="tooltipProps"
                      :icon="item.status === 'active' ? 'mdi-pause-circle-outline' : 'mdi-check-circle-outline'"
                      size="small"
                      variant="text"
                      :color="item.status === 'active' ? 'warning' : 'success'"
                      :loading="actionLoadingId === item.id"
                      :aria-label="item.status === 'active' ? 'Выключить источник' : 'Активировать источник'"
                      @click="toggleSourceStatus(item)"
                    />
                  </template>
                </v-tooltip>
              </div>
            </template>

            <template #no-data>
              <div class="fp-pricing-empty-state">
                <v-icon size="40" color="medium-emphasis" class="mb-3">mdi-currency-rub</v-icon>
                <div class="text-subtitle-1 font-weight-medium mb-1">Источники цены пока не настроены</div>
                <div class="text-body-2 text-medium-emphasis mb-4">
                  Добавьте первую цену поставщика или другой источник расчёта.
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
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
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
const suppressProfileAutoSave = ref(false)
let profileSaveTimer: number | null = null
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
  { title: 'Цена за м²', key: 'price_per_m2_normalized', sortable: false, width: '130px' },
  { title: 'Статус', key: 'status', sortable: false, width: '120px' },
  { title: 'Дата', key: 'observed_at', sortable: false, width: '130px' },
  { title: 'Описание', key: 'description', sortable: false },
  { title: 'Доказательства', key: 'evidence_assets_count', sortable: false, width: '150px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '156px', align: 'center' as const },
]

const specificationSubtitle = computed(() => {
  if (!specification.value) return 'Загружаем спецификацию...'

  const parts = [
    specification.value.article || null,
    specification.value.base_type ? formatFacadeFeature(specification.value.base_type) : null,
    specification.value.thickness_mm ? `${specification.value.thickness_mm} мм` : null,
    specification.value.covering ? formatFacadeFeature(specification.value.covering) : null,
    specification.value.collection || null,
  ].filter(Boolean)

  return parts.length > 0 ? parts.join(' · ') : 'Фасадная спецификация'
})

const facadeFeatureLabels: Record<string, string> = {
  mdf: 'МДФ',
  ldf: 'ЛДФ',
  chipboard: 'ЛДСП',
  pvc_film: 'ПВХ-плёнка',
  enamel: 'Эмаль',
  veneer: 'Шпон',
}

function formatFacadeFeature(value: string): string {
  return facadeFeatureLabels[value] ?? value.replace(/_/g, ' ')
}

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
  suppressProfileAutoSave.value = true
  profileForm.method = breakdown.profile.method ?? 'median'
  profileForm.include_only_active = breakdown.profile.include_only_active
  profileForm.exclude_stale = breakdown.profile.exclude_stale
  profileForm.minimum_sources_count = breakdown.profile.minimum_sources_count
  window.setTimeout(() => {
    suppressProfileAutoSave.value = false
  }, 0)
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
    error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось загрузить цены фасада'
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

function scheduleProfileSave() {
  if (suppressProfileAutoSave.value) return

  if (profileSaveTimer) {
    window.clearTimeout(profileSaveTimer)
  }

  profileSaveTimer = window.setTimeout(() => {
    void saveProfile()
  }, 600)
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
  } catch (e: any) {
    showSnack(e?.response?.data?.message ?? 'Не удалось пересчитать цену', 'error')
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
  showSnack('Доказательства обновлены', 'success')
}

watch(profileForm, scheduleProfileSave, { deep: true })

watch(
  () => props.specificationId,
  () => {
    loadAll()
  },
)

onMounted(() => {
  loadAll()
})

onBeforeUnmount(() => {
  if (profileSaveTimer) {
    window.clearTimeout(profileSaveTimer)
  }
})
</script>

<style scoped>
.fp-pricing {
  display: grid;
  gap: var(--ds-space-12);
}

.fp-pricing-hero,
.fp-pricing-body {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface-container-low), 0.94);
}

.fp-pricing-hero {
  padding: var(--ds-space-16);
}

.fp-pricing-hero__content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-16);
  flex-wrap: wrap;
}

.fp-pricing-hero__title {
  font-size: 1.25rem;
  line-height: 1.25;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.fp-pricing-hero__subtitle {
  margin-top: 4px;
  color: var(--ds-text-secondary);
}

.fp-pricing-hero__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-8);
  flex-wrap: wrap;
}

.fp-pricing-body {
  display: grid;
  gap: var(--ds-space-16);
  padding: var(--ds-space-16);
}

.fp-pricing-layout {
  margin-bottom: 0 !important;
}

.fp-pricing-metric,
.fp-pricing-profile-card,
.fp-pricing-sources-card {
  border-color: rgba(var(--v-theme-outline-variant), 0.72) !important;
  background: rgba(var(--v-theme-surface), 0.96) !important;
}

.fp-pricing-metric {
  display: grid;
  align-content: start;
  gap: 4px;
  padding: var(--ds-space-14);
}

.fp-pricing-metric__label {
  font-size: 0.75rem;
  color: var(--ds-text-secondary);
}

.fp-pricing-metric__value {
  font-size: 1.125rem;
  font-weight: 800;
  color: var(--ds-text-primary);
}

.fp-pricing-metric__body {
  font-weight: 700;
  color: var(--ds-text-primary);
}

.fp-pricing-metric__hint {
  font-size: 0.75rem;
  color: var(--ds-text-secondary);
}

.fp-pricing-card-title,
.fp-pricing-sources-card__title {
  min-height: 56px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
  background: rgba(var(--v-theme-surface-container-lowest), 0.64);
}

.fp-pricing-profile-form {
  display: grid;
  gap: var(--ds-space-12);
  padding-top: var(--ds-space-14) !important;
}

.fp-pricing-sources-card {
  overflow: hidden;
}

.fp-pricing-sources-card__content {
  padding: 0 !important;
}

.fp-pricing-table :deep(.v-table__wrapper) {
  border-radius: 0;
  background: rgba(var(--v-theme-surface), 0.98);
}

.fp-pricing-table :deep(thead th) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.68) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.92) !important;
}

.fp-pricing-table :deep(tbody td) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.44) !important;
}

.fp-pricing-row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 2px 4px;
  border-radius: var(--ds-radius-full);
  background: rgba(var(--v-theme-surface-container-low), 0.86);
}

.fp-pricing-row-actions :deep(.v-btn) {
  color: var(--ds-text-secondary);
}

.fp-pricing-row-actions :deep(.v-btn:hover) {
  background: rgba(var(--v-theme-primary), 0.08);
  color: rgb(var(--v-theme-primary));
}

.fp-pricing-row-actions :deep(.v-btn:focus-visible) {
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.16);
}

.fp-pricing-empty-state {
  padding: var(--ds-space-40) var(--ds-space-16);
  text-align: center;
}

@media (max-width: 760px) {
  .fp-pricing-hero__actions {
    width: 100%;
    justify-content: flex-start;
  }
}
</style>
