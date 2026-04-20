<template>
  <PageContainer>
    <PageHeader
      title="Цены / Труд"
      subtitle="Управление источниками обоснования ставок труда, профилями работ и каталогом сайтов"
    >
      <template #actions>
        <v-btn variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'pricing' }">
          К разделам
        </v-btn>
      </template>
    </PageHeader>

    <v-tabs v-model="activeTab" color="primary" class="mb-4">
      <v-tab value="sources">Источники</v-tab>
      <v-tab value="profiles">Профили</v-tab>
      <v-tab value="providers">Источники сайтов</v-tab>
    </v-tabs>

    <v-window v-model="activeTab">
      <v-window-item value="sources">
        <SectionCard>
          <template #title>Источники обоснования труда</template>

          <div class="section-head">
            <div class="section-head__copy">
              <div class="section-head__title">Источники</div>
              <div class="section-head__text">
                Добавляйте вакансии, ссылки и подтверждающие файлы. Источники попадут в отчёт только после привязки к проекту.
              </div>
            </div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openSourceDialog()">
              Добавить источник
            </v-btn>
          </div>

          <div v-if="sources.length === 0 && !loading.sources" class="empty-wrap">
            <EmptyState
              icon="mdi-account-search-outline"
              title="Источники труда ещё не добавлены"
              description="Добавьте вакансию вручную или через Chrome, чтобы затем привязать источник к проекту."
            >
              <template #actions>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openSourceDialog()">
                  Добавить источник
                </v-btn>
              </template>
            </EmptyState>
          </div>

          <div v-else class="profile-groups">
            <div
              v-for="group in groupedSourceBlocks"
              :key="group.profile.id"
              class="profile-group-card"
            >
              <div class="profile-group-card__head">
                <div>
                  <div class="profile-group-card__title">
                    {{ group.profile.title }}
                    <span class="profile-group-card__count">({{ group.sources.length }} {{ sourceWord(group.sources.length) }})</span>
                  </div>
                  <div class="profile-group-card__meta">
                    Валидных ставок: {{ group.validRatesCount }}
                    <span v-if="group.averageRateLabel"> · Средняя ставка: {{ group.averageRateLabel }}</span>
                  </div>
                </div>
              </div>

              <v-data-table
                :headers="sourceHeaders"
                :items="group.sources"
                item-value="id"
                density="compact"
                :loading="loading.sources"
              >
                <template #[`item.vacancy_title`]="{ item }">
                  <div class="source-title-cell">
                    <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
                    <div class="source-title-cell__meta">{{ item.employer_name || 'Работодатель не указан' }}</div>
                  </div>
                </template>

                <template #[`item.provider`]="{ item }">
                  {{ item.provider?.title || '—' }}
                </template>

                <template #[`item.region`]="{ item }">
                  {{ laborRegionLabel(item.region) }}
                </template>

                <template #[`item.rate`]="{ item }">
                  {{ sourceRateLabel(item) }}
                </template>

                <template #[`item.assets`]="{ item }">
                  <v-chip :color="evidenceStatus(item).color" variant="tonal" size="small">
                    {{ evidenceStatus(item).label }}
                  </v-chip>
                </template>

                <template #[`item.created_at`]="{ item }">
                  {{ dateLabel(item.created_at) }}
                </template>

                <template #[`item.actions`]="{ item }">
                  <div class="d-flex ga-1 justify-end">
                    <v-btn size="small" variant="text" color="primary" @click="openDetails(item)">
                      Детали
                    </v-btn>
                    <v-btn size="small" variant="text" color="primary" @click="openSourceDialog(item)">
                      Изменить
                    </v-btn>
                    <v-btn size="small" variant="text" color="error" @click="removeSource(item)">
                      Удалить
                    </v-btn>
                  </div>
                </template>
              </v-data-table>
            </div>

            <div v-if="legacySourceBlock.length" class="profile-group-card profile-group-card--legacy">
              <div class="profile-group-card__head">
                <div>
                  <div class="profile-group-card__title">
                    Источники без профиля (устаревшие)
                    <span class="profile-group-card__count">({{ legacySourceBlock.length }} {{ sourceWord(legacySourceBlock.length) }})</span>
                  </div>
                  <div class="profile-group-card__meta">
                    Эти записи не участвуют в расчёте по профилям, пока им не назначен профиль работ.
                  </div>
                </div>
              </div>

              <v-data-table
                :headers="sourceHeaders"
                :items="legacySourceBlock"
                item-value="id"
                density="compact"
                :loading="loading.sources"
              >
                <template #[`item.vacancy_title`]="{ item }">
                  <div class="source-title-cell">
                    <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
                    <div class="source-title-cell__meta">{{ item.employer_name || 'Работодатель не указан' }}</div>
                  </div>
                </template>

                <template #[`item.provider`]="{ item }">
                  {{ item.provider?.title || '—' }}
                </template>

                <template #[`item.region`]="{ item }">
                  {{ laborRegionLabel(item.region) }}
                </template>

                <template #[`item.rate`]="{ item }">
                  {{ sourceRateLabel(item) }}
                </template>

                <template #[`item.assets`]="{ item }">
                  <v-chip :color="evidenceStatus(item).color" variant="tonal" size="small">
                    {{ evidenceStatus(item).label }}
                  </v-chip>
                </template>

                <template #[`item.created_at`]="{ item }">
                  {{ dateLabel(item.created_at) }}
                </template>

                <template #[`item.actions`]="{ item }">
                  <div class="d-flex ga-1 justify-end">
                    <v-btn size="small" variant="text" color="primary" @click="openDetails(item)">
                      Детали
                    </v-btn>
                    <v-btn size="small" variant="text" color="primary" @click="openSourceDialog(item)">
                      Изменить
                    </v-btn>
                    <v-btn size="small" variant="text" color="error" @click="removeSource(item)">
                      Удалить
                    </v-btn>
                  </div>
                </template>
              </v-data-table>
            </div>
          </div>
        </SectionCard>
      </v-window-item>

      <v-window-item value="profiles">
        <SectionCard>
          <template #title>Профили работ</template>

          <div class="section-head">
            <div class="section-head__copy">
              <div class="section-head__title">Профили работ</div>
              <div class="section-head__text">Управляйте списком профилей работ для обоснования трудовых ставок.</div>
            </div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openProfileDialog()">
              Добавить профиль
            </v-btn>
          </div>

          <v-data-table
            :headers="profileHeaders"
            :items="profiles"
            item-value="id"
            density="compact"
            :loading="loading.profiles"
          >
            <template #[`item.actions`]="{ item }">
              <div class="d-flex ga-1 justify-end">
                <v-btn size="small" variant="text" color="primary" @click="openProfileDialog(item)">Изменить</v-btn>
                <v-btn size="small" variant="text" color="error" @click="removeProfile(item)">Удалить</v-btn>
              </div>
            </template>
          </v-data-table>
        </SectionCard>
      </v-window-item>

      <v-window-item value="providers">
        <SectionCard>
          <template #title>Каталог сайтов и источников</template>

          <div class="section-head">
            <div class="section-head__copy">
              <div class="section-head__title">Каталог источников</div>
              <div class="section-head__text">Сайты и источники, которые можно выбирать при заполнении вакансии.</div>
            </div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openProviderDialog()">
              Добавить провайдера
            </v-btn>
          </div>

          <v-data-table
            :headers="providerHeaders"
            :items="providers"
            item-value="id"
            density="compact"
            :loading="loading.providers"
          >
            <template #[`item.actions`]="{ item }">
              <div class="d-flex ga-1 justify-end">
                <v-btn size="small" variant="text" color="primary" @click="openProviderDialog(item)">Изменить</v-btn>
                <v-btn size="small" variant="text" color="error" @click="removeProvider(item)">Удалить</v-btn>
              </div>
            </template>
          </v-data-table>
        </SectionCard>
      </v-window-item>
    </v-window>

    <v-dialog v-model="sourceDialog" max-width="860" scrollable>
      <v-card>
        <v-card-title>{{ editingSource ? 'Редактировать источник' : 'Новый источник труда' }}</v-card-title>
        <v-card-text class="pa-5">
          <v-alert v-if="sourceSubmitError" type="error" variant="tonal" class="mb-4" closable @click:close="sourceSubmitError = ''">
            {{ sourceSubmitError }}
          </v-alert>
          <v-form @submit.prevent="saveSource">
            <v-row dense>
              <v-col cols="12" md="8">
                <v-text-field v-model="sourceForm.vacancy_title" label="Название вакансии" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="sourceForm.employer_name" label="Работодатель" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="8">
                <v-select
                  v-model="sourceForm.provider_id"
                  :items="providerOptions"
                  item-title="title"
                  item-value="id"
                  label="Сайт / источник"
                  variant="outlined"
                  density="compact"
                  :error-messages="sourceProviderErrors"
                />
              </v-col>
              <v-col cols="12" md="4" class="d-flex align-center">
                <v-btn variant="text" prepend-icon="mdi-plus" @click="openProviderDialog()">
                  Новый источник
                </v-btn>
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="sourceForm.region_id"
                  :items="regionOptions"
                  item-title="title"
                  item-value="id"
                  label="Регион"
                  variant="outlined"
                  density="compact"
                  :error-messages="sourceRegionErrors"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="sourceForm.labor_profile_id"
                  :items="profileOptions"
                  item-title="title"
                  item-value="id"
                  label="Профиль работ"
                  variant="outlined"
                  density="compact"
                  :error-messages="sourceProfileErrors"
                />
              </v-col>
              <v-col cols="12">
                <v-text-field
                  v-model="sourceForm.source_url"
                  label="Ссылка на источник"
                  variant="outlined"
                  density="compact"
                  :error-messages="sourceUrlErrors"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model="sourceForm.source_title" label="Название источника" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model="sourceForm.source_date" label="Дата источника" type="date" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12">
                <v-textarea v-model="sourceForm.salary_raw_text" label="Текст зарплаты / ставки" variant="outlined" density="compact" rows="2" />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model.number="sourceForm.salary_value" label="Сумма" type="number" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model.number="sourceForm.salary_value_min" label="Минимум" type="number" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model.number="sourceForm.salary_value_max" label="Максимум" type="number" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="4">
                <v-select v-model="sourceForm.salary_period" :items="salaryPeriods" label="Период оплаты" variant="outlined" density="compact" clearable />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model.number="sourceForm.hours_per_month" label="Часов в месяц" type="number" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model.number="sourceForm.derived_hourly_rate" label="Ставка за час" type="number" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12">
                <v-textarea v-model="sourceForm.vacancy_description" label="Описание вакансии" variant="outlined" density="compact" rows="4" />
              </v-col>
              <v-col cols="12">
                <v-textarea v-model="sourceForm.note" label="Примечание" variant="outlined" density="compact" rows="2" />
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>
        <v-card-actions class="px-5 pb-5">
          <v-spacer />
          <v-btn variant="text" @click="sourceDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.source" :disabled="!canSubmitSource" @click="saveSource">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="providerDialog" max-width="520">
      <v-card>
        <v-card-title>{{ editingProvider ? 'Редактировать источник' : 'Новый источник' }}</v-card-title>
        <v-card-text class="pa-5">
          <v-text-field v-model="providerForm.title" label="Название" variant="outlined" density="compact" class="mb-3" />
          <v-text-field v-model="providerForm.domain" label="Домен" variant="outlined" density="compact" class="mb-3" />
          <v-text-field v-model="providerForm.base_url" label="Базовая ссылка" variant="outlined" density="compact" />
        </v-card-text>
        <v-card-actions class="px-5 pb-5">
          <v-spacer />
          <v-btn variant="text" @click="providerDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.provider" @click="saveProvider">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="profileDialog" max-width="520">
      <v-card>
        <v-card-title>{{ editingProfile ? 'Редактировать профиль' : 'Новый профиль' }}</v-card-title>
        <v-card-text class="pa-5">
          <v-text-field v-model="profileForm.title" label="Название" variant="outlined" density="compact" class="mb-3" />
          <v-textarea v-model="profileForm.description" label="Описание" variant="outlined" density="compact" rows="3" />
        </v-card-text>
        <v-card-actions class="px-5 pb-5">
          <v-spacer />
          <v-btn variant="text" @click="profileDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.profile" @click="saveProfile">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <LaborEvidenceDetailsDialog v-model="detailsDialog" :source="detailsSource" @source-updated="handleSourceUpdated" />
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import LaborEvidenceDetailsDialog from '@/components/pricing/LaborEvidenceDetailsDialog.vue'
import {
  laborEvidenceApi,
  laborEvidenceRecordOf,
  laborRegionLabel,
  type LaborEvidenceSource,
  type LaborProfile,
  type LaborProvider,
  type LaborSourcePayload,
  type RegionOption,
} from '@/api/laborEvidence'
import api from '@/api/axios'

const activeTab = ref<'sources' | 'profiles' | 'providers'>('sources')
const loading = reactive({ sources: false, providers: false, profiles: false })
const saving = reactive({ source: false, provider: false, profile: false })

const sources = ref<LaborEvidenceSource[]>([])
const providers = ref<LaborProvider[]>([])
const profiles = ref<LaborProfile[]>([])
const regions = ref<RegionOption[]>([])
const defaultRegionId = ref<number | null>(null)

const sourceDialog = ref(false)
const providerDialog = ref(false)
const profileDialog = ref(false)
const detailsDialog = ref(false)
const detailsSource = ref<LaborEvidenceSource | null>(null)

const editingSource = ref<LaborEvidenceSource | null>(null)
const editingProvider = ref<LaborProvider | null>(null)
const editingProfile = ref<LaborProfile | null>(null)
const sourceSubmitError = ref('')
const sourceFieldErrors = reactive<Record<string, string[]>>({
  source_url: [],
  labor_profile_id: [],
  provider_id: [],
  region_id: [],
})

const sourceForm = reactive<LaborSourcePayload>({
  region_id: 0,
  provider_id: 0,
  labor_profile_id: null,
  source_title: '',
  source_url: '',
  source_date: null,
  employer_name: '',
  vacancy_title: '',
  vacancy_description: '',
  vacancy_excerpt: '',
  salary_raw_text: '',
  salary_value: null,
  salary_value_min: null,
  salary_value_max: null,
  salary_period: null,
  hours_per_month: 160,
  derived_hourly_rate: null,
  currency: 'RUB',
  note: '',
  captured_via: 'manual',
  verification_status: 'pending',
  is_active: true,
})

const providerForm = reactive<Partial<LaborProvider>>({
  title: '',
  domain: '',
  base_url: '',
  is_active: true,
  sort_order: 0,
})

const profileForm = reactive<Partial<LaborProfile>>({
  title: '',
  description: '',
  is_active: true,
  sort_order: 0,
})

const sourceHeaders = [
  { title: 'Вакансия', key: 'vacancy_title', sortable: false },
  { title: 'Источник', key: 'provider', sortable: false },
  { title: 'Регион', key: 'region', sortable: false },
  { title: 'Зарплата / ставка', key: 'rate', sortable: false, align: 'end' as const },
  { title: 'Подтверждение', key: 'assets', sortable: false, align: 'center' as const },
  { title: 'Создано', key: 'created_at', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const providerHeaders = [
  { title: 'Название', key: 'title', sortable: false },
  { title: 'Домен', key: 'domain', sortable: false },
  { title: 'Базовая ссылка', key: 'base_url', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const profileHeaders = [
  { title: 'Название', key: 'title', sortable: false },
  { title: 'Описание', key: 'description', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const salaryPeriods = [
  { title: 'За час', value: 'hour' },
  { title: 'За день', value: 'day' },
  { title: 'За месяц', value: 'month' },
  { title: 'За год', value: 'year' },
  { title: 'За проект', value: 'project' },
]

const regionOptions = computed(() =>
  regions.value.map(region => ({
    id: region.id,
    title: laborRegionLabel(region),
  })),
)

const providerOptions = computed(() =>
  providers.value.map(provider => ({
    id: provider.id,
    title: provider.title,
  })),
)

const profileOptions = computed(() =>
  profiles.value.map(profile => ({
    id: profile.id,
    title: profile.title,
  })),
)

const sourceProfileErrors = computed(() => (
  sourceFieldErrors.labor_profile_id.length
    ? sourceFieldErrors.labor_profile_id
    : (sourceForm.labor_profile_id ? [] : ['Выберите профиль работ'])
))

const sourceProviderErrors = computed(() => (
  sourceFieldErrors.provider_id.length
    ? sourceFieldErrors.provider_id
    : []
))

const sourceRegionErrors = computed(() => (
  sourceFieldErrors.region_id.length
    ? sourceFieldErrors.region_id
    : []
))

const sourceUrlErrors = computed(() => (
  sourceFieldErrors.source_url.length
    ? sourceFieldErrors.source_url
    : []
))

const canSubmitSource = computed(() => (
  Boolean(sourceForm.region_id) &&
  Boolean(sourceForm.provider_id) &&
  Boolean(sourceForm.labor_profile_id) &&
  Boolean(String(sourceForm.source_url || '').trim())
))

const sortedSources = computed(() => (
  [...sources.value].sort((left, right) => {
    const leftTime = left.created_at ? new Date(left.created_at).getTime() : 0
    const rightTime = right.created_at ? new Date(right.created_at).getTime() : 0
    return rightTime - leftTime
  })
))

const groupedSourceBlocks = computed(() => {
  const groups = new Map<number, { profile: LaborProfile; sources: LaborEvidenceSource[] }>()

  for (const source of sortedSources.value) {
    const profile = source.laborProfile || source.labor_profile
    if (!source.labor_profile_id || !profile) continue

    if (!groups.has(source.labor_profile_id)) {
      groups.set(source.labor_profile_id, {
        profile,
        sources: [],
      })
    }

    groups.get(source.labor_profile_id)?.sources.push(source)
  }

  return [...groups.values()]
    .sort((left, right) => left.profile.title.localeCompare(right.profile.title, 'ru'))
    .map(group => {
      const validRates = group.sources
        .map(source => numericRateValue(source))
        .filter((value): value is number => value !== null)

      return {
        ...group,
        validRatesCount: validRates.length,
        averageRateLabel: validRates.length
          ? `${formatRateNumber(validRates.reduce((sum, value) => sum + value, 0) / validRates.length)} ₽/ч`
          : null,
      }
    })
})

const legacySourceBlock = computed(() => (
  sortedSources.value.filter(source => !source.labor_profile_id || !(source.laborProfile || source.labor_profile))
))

onMounted(() => {
  void bootstrap()
})

async function bootstrap() {
  await Promise.all([
    loadSources(),
    loadProviders(),
    loadProfiles(),
    loadRegions(),
    loadUserSettings(),
  ])
}

async function loadSources() {
  loading.sources = true
  try {
    const response = await laborEvidenceApi.listSources({ per_page: 100 })
    sources.value = response.data || []
  } finally {
    loading.sources = false
  }
}

async function loadProviders() {
  loading.providers = true
  try {
    const response = await laborEvidenceApi.listProviders({ per_page: 100 })
    providers.value = response.data || []
  } finally {
    loading.providers = false
  }
}

async function loadProfiles() {
  loading.profiles = true
  try {
    const response = await laborEvidenceApi.listProfiles({ per_page: 100 })
    profiles.value = response.data || []
  } finally {
    loading.profiles = false
  }
}

async function loadRegions() {
  const response = await api.get('/api/regions')
  regions.value = response.data?.data || []
}

async function loadUserSettings() {
  const settings = await laborEvidenceApi.getUserSettings()
  defaultRegionId.value = settings.region_id ?? null
}

function resetSourceForm() {
  resetSourceErrors()
  Object.assign(sourceForm, {
    region_id: defaultRegionId.value || 0,
    provider_id: providers.value[0]?.id || 0,
    labor_profile_id: null,
    source_title: '',
    source_url: '',
    source_date: null,
    employer_name: '',
    vacancy_title: '',
    vacancy_description: '',
    vacancy_excerpt: '',
    salary_raw_text: '',
    salary_value: null,
    salary_value_min: null,
    salary_value_max: null,
    salary_period: null,
    hours_per_month: 160,
    derived_hourly_rate: null,
    currency: 'RUB',
    note: '',
    captured_via: 'manual',
    verification_status: 'pending',
    is_active: true,
  })
}

function resetSourceErrors() {
  sourceSubmitError.value = ''
  sourceFieldErrors.source_url = []
  sourceFieldErrors.labor_profile_id = []
  sourceFieldErrors.provider_id = []
  sourceFieldErrors.region_id = []
}

function openSourceDialog(source?: LaborEvidenceSource) {
  editingSource.value = source || null
  resetSourceForm()

  if (source) {
    Object.assign(sourceForm, {
      region_id: source.region_id,
      provider_id: source.provider_id,
      labor_profile_id: source.labor_profile_id,
      source_title: source.source_title,
      source_url: source.source_url,
      source_date: source.source_date ? String(source.source_date).slice(0, 10) : null,
      employer_name: source.employer_name,
      vacancy_title: source.vacancy_title,
      vacancy_description: source.vacancy_description,
      vacancy_excerpt: source.vacancy_excerpt,
      salary_raw_text: source.salary_raw_text,
      salary_value: toNullableNumber(source.salary_value),
      salary_value_min: toNullableNumber(source.salary_value_min),
      salary_value_max: toNullableNumber(source.salary_value_max),
      salary_period: source.salary_period,
      hours_per_month: source.hours_per_month || 160,
      derived_hourly_rate: toNullableNumber(source.derived_hourly_rate),
      currency: source.currency || 'RUB',
      note: source.note,
      captured_via: source.captured_via,
      verification_status: source.verification_status,
      is_active: source.is_active,
    })
  }

  sourceDialog.value = true
}

function openProviderDialog(provider?: LaborProvider) {
  editingProvider.value = provider || null
  Object.assign(providerForm, {
    title: provider?.title || '',
    domain: provider?.domain || '',
    base_url: provider?.base_url || '',
    is_active: provider?.is_active ?? true,
    sort_order: provider?.sort_order ?? 0,
  })
  providerDialog.value = true
}

function openProfileDialog(profile?: LaborProfile) {
  editingProfile.value = profile || null
  Object.assign(profileForm, {
    title: profile?.title || '',
    description: profile?.description || '',
    is_active: profile?.is_active ?? true,
    sort_order: profile?.sort_order ?? 0,
  })
  profileDialog.value = true
}

async function saveSource() {
  if (!canSubmitSource.value) {
    return
  }

  saving.source = true
  try {
    resetSourceErrors()
    sourceForm.source_url = normalizeSourceUrl(sourceForm.source_url)

    if (editingSource.value?.id) {
      await laborEvidenceApi.updateSource(editingSource.value.id, sourceForm)
    } else {
      await laborEvidenceApi.createSource(sourceForm)
    }
    sourceDialog.value = false
    await loadSources()
  } catch (error: any) {
    const fieldErrors = error?.response?.data?.errors

    if (fieldErrors && typeof fieldErrors === 'object') {
      sourceFieldErrors.source_url = Array.isArray(fieldErrors.source_url) ? fieldErrors.source_url : []
      sourceFieldErrors.labor_profile_id = Array.isArray(fieldErrors.labor_profile_id) ? fieldErrors.labor_profile_id : []
      sourceFieldErrors.provider_id = Array.isArray(fieldErrors.provider_id) ? fieldErrors.provider_id : []
      sourceFieldErrors.region_id = Array.isArray(fieldErrors.region_id) ? fieldErrors.region_id : []

      const firstFieldError = Object.values(fieldErrors).find(value => Array.isArray(value) && value.length > 0) as string[] | undefined
      sourceSubmitError.value = firstFieldError?.[0] || error?.response?.data?.message || 'Не удалось сохранить источник.'
    } else {
      sourceSubmitError.value = error?.response?.data?.message || 'Не удалось сохранить источник.'
    }
  } finally {
    saving.source = false
  }
}

async function saveProvider() {
  saving.provider = true
  try {
    if (editingProvider.value?.id) {
      await laborEvidenceApi.updateProvider(editingProvider.value.id, providerForm)
    } else {
      const created = await laborEvidenceApi.createProvider(providerForm)
      if (!sourceForm.provider_id) sourceForm.provider_id = created.id
    }
    providerDialog.value = false
    await loadProviders()
  } finally {
    saving.provider = false
  }
}

async function saveProfile() {
  saving.profile = true
  try {
    if (editingProfile.value?.id) {
      await laborEvidenceApi.updateProfile(editingProfile.value.id, profileForm)
    } else {
      const created = await laborEvidenceApi.createProfile(profileForm)
      if (!sourceForm.labor_profile_id) sourceForm.labor_profile_id = created.id
    }
    profileDialog.value = false
    await loadProfiles()
  } finally {
    saving.profile = false
  }
}

async function removeSource(source: LaborEvidenceSource) {
  if (!window.confirm(`Удалить источник "${source.vacancy_title || source.source_title || 'без названия'}"?`)) return
  await laborEvidenceApi.deleteSource(source.id)
  await loadSources()
}

async function removeProvider(provider: LaborProvider) {
  if (!window.confirm(`Удалить provider "${provider.title}"?`)) return
  await laborEvidenceApi.deleteProvider(provider.id)
  await loadProviders()
}

async function removeProfile(profile: LaborProfile) {
  if (!window.confirm(`Удалить профиль "${profile.title}"?`)) return
  await laborEvidenceApi.deleteProfile(profile.id)
  await loadProfiles()
}

function handleSourceUpdated(source: LaborEvidenceSource) {
  detailsSource.value = source
  sources.value = sources.value.map(item => (item.id === source.id ? source : item))
}

function openDetails(source: LaborEvidenceSource) {
  detailsSource.value = source
  detailsDialog.value = true
}

function sourceRateLabel(source: LaborEvidenceSource): string {
  if (source.derived_hourly_rate) return `${source.derived_hourly_rate} ${source.currency || 'RUB'} / ч`
  if (source.salary_raw_text) return source.salary_raw_text
  if (source.salary_value) return `${source.salary_value} ${source.currency || 'RUB'}`
  if (source.salary_value_min || source.salary_value_max) {
    const from = source.salary_value_min ? `от ${source.salary_value_min}` : ''
    const to = source.salary_value_max ? `до ${source.salary_value_max}` : ''
    return `${from} ${to}`.trim() || '—'
  }
  return '—'
}

function hasScreenshot(source: LaborEvidenceSource): boolean {
  return (laborEvidenceRecordOf(source)?.assets || []).some(asset => asset.asset_type === 'screenshot')
}

function hasStructuredData(source: LaborEvidenceSource): boolean {
  return Boolean(
    source.vacancy_title ||
    source.salary_raw_text ||
    source.salary_value ||
    source.salary_value_min ||
    source.salary_value_max ||
    source.derived_hourly_rate,
  )
}

function evidenceStatus(source: LaborEvidenceSource): { label: string; color: string } {
  if (!hasScreenshot(source)) {
    return { label: 'Нет подтверждения', color: 'error' }
  }

  if (hasScreenshot(source) && hasStructuredData(source)) {
    return { label: 'Полное подтверждение', color: 'success' }
  }

  return { label: 'Есть скриншот', color: 'warning' }
}

function dateLabel(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('ru-RU')
}

function numericRateValue(source: LaborEvidenceSource): number | null {
  if (source.derived_hourly_rate !== null && source.derived_hourly_rate !== undefined && source.derived_hourly_rate !== '') {
    const rate = Number(source.derived_hourly_rate)
    return Number.isFinite(rate) ? rate : null
  }

  if (source.salary_period === 'hour' && source.salary_value !== null && source.salary_value !== undefined && source.salary_value !== '') {
    const rate = Number(source.salary_value)
    return Number.isFinite(rate) ? rate : null
  }

  return null
}

function formatRateNumber(value: number): string {
  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(value)
}

function sourceWord(count: number): string {
  const mod10 = count % 10
  const mod100 = count % 100

  if (mod10 === 1 && mod100 !== 11) return 'источник'
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'источника'
  return 'источников'
}

function toNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function normalizeSourceUrl(value: string | null | undefined): string {
  const trimmed = String(value || '').trim()
  if (!trimmed) return ''

  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed
  }

  return `https://${trimmed}`
}
</script>

<style scoped>
.section-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 18px;
}

.section-head__title {
  font-size: 15px;
  font-weight: 600;
}

.section-head__text {
  margin-top: 4px;
  color: rgba(0, 0, 0, 0.6);
  max-width: 720px;
}

.empty-wrap {
  padding: 8px 0;
}

.profile-groups {
  display: grid;
  gap: 18px;
}

.profile-group-card {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 18px;
  overflow: hidden;
  background: #fff;
}

.profile-group-card--legacy {
  border-style: dashed;
  background: #fffaf4;
}

.profile-group-card__head {
  padding: 16px 18px 10px;
}

.profile-group-card__title {
  font-size: 16px;
  font-weight: 700;
  line-height: 1.3;
}

.profile-group-card__count {
  font-weight: 500;
  color: rgba(0, 0, 0, 0.56);
}

.profile-group-card__meta {
  margin-top: 4px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.58);
}

.source-title-cell__title {
  font-weight: 600;
  line-height: 1.35;
}

.source-title-cell__meta {
  margin-top: 2px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.56);
}

@media (max-width: 900px) {
  .section-head {
    flex-direction: column;
  }
}
</style>
