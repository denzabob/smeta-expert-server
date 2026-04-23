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

    <AppTabs
      v-model="activeTab"
      :items="laborTabItems"
      class="mb-4 labor-tabs"
    />

    <v-window v-model="activeTab">
      <v-window-item value="sources">
        <SectionCard
          class="labor-card"
          subtitle="Источники ставок труда, сгруппированные по профилям работ и готовые к использованию в отчётах."
        >
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
            <AppStateBlock
              icon="mdi-account-search-outline"
              title="Источники труда ещё не добавлены"
              description="Добавьте вакансию вручную или через Chrome, чтобы затем привязать источник к проекту."
            >
              <template #actions>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openSourceDialog()">
                  Добавить источник
                </v-btn>
              </template>
            </AppStateBlock>
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
                class="labor-table"
              >
                <template #[`item.vacancy_title`]="{ item }">
                  <div class="source-title-cell">
                    <button type="button" class="source-title-cell__link" @click="openDetails(item)">
                      <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
                      <div class="source-title-cell__meta">{{ item.employer_name || 'Работодатель не указан' }}</div>
                    </button>
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
                  <StatusChip :color="evidenceStatus(item).color" variant="tonal" size="small">
                    {{ evidenceStatus(item).label }}
                  </StatusChip>
                </template>

                <template #[`item.created_at`]="{ item }">
                  {{ dateLabel(item.created_at) }}
                </template>

                <template #[`item.actions`]="{ item }">
                  <AppRowActions class="source-actions">
                    <v-btn
                      icon="mdi-pencil"
                      size="small"
                      variant="text"
                      color="primary"
                      class="source-actions__icon"
                      @click="openSourceDialog(item)"
                    />
                    <v-btn
                      icon="mdi-delete"
                      size="small"
                      variant="text"
                      color="error"
                      class="source-actions__icon"
                      @click="removeSource(item)"
                    />
                  </AppRowActions>
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
                class="labor-table"
              >
                <template #[`item.vacancy_title`]="{ item }">
                  <div class="source-title-cell">
                    <button type="button" class="source-title-cell__link" @click="openDetails(item)">
                      <div class="source-title-cell__title">{{ item.vacancy_title || item.source_title || 'Без названия' }}</div>
                      <div class="source-title-cell__meta">{{ item.employer_name || 'Работодатель не указан' }}</div>
                    </button>
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
                  <StatusChip :color="evidenceStatus(item).color" variant="tonal" size="small">
                    {{ evidenceStatus(item).label }}
                  </StatusChip>
                </template>

                <template #[`item.created_at`]="{ item }">
                  {{ dateLabel(item.created_at) }}
                </template>

                <template #[`item.actions`]="{ item }">
                  <AppRowActions class="source-actions">
                    <v-btn
                      icon="mdi-pencil"
                      size="small"
                      variant="text"
                      color="primary"
                      class="source-actions__icon"
                      @click="openSourceDialog(item)"
                    />
                    <v-btn
                      icon="mdi-delete"
                      size="small"
                      variant="text"
                      color="error"
                      class="source-actions__icon"
                      @click="removeSource(item)"
                    />
                  </AppRowActions>
                </template>
              </v-data-table>
            </div>
          </div>
        </SectionCard>
      </v-window-item>

      <v-window-item value="profiles">
        <SectionCard
          class="labor-card"
          subtitle="Справочник профилей работ для группировки источников и настройки расчёта трудовых ставок."
        >
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
            class="labor-table"
          >
            <template #[`item.title`]="{ item }">
              <div class="reference-cell">
                <div class="reference-cell__title">{{ item.title || 'Без названия' }}</div>
              </div>
            </template>

            <template #[`item.description`]="{ item }">
              <div class="reference-cell">
                <div class="reference-cell__text">
                  {{ item.description || 'Описание не заполнено' }}
                </div>
              </div>
            </template>

            <template #[`item.actions`]="{ item }">
              <AppRowActions class="source-actions">
                <v-btn
                  icon="mdi-pencil"
                  size="small"
                  variant="text"
                  color="primary"
                  class="source-actions__icon"
                  @click="openProfileDialog(item)"
                />
                <v-btn
                  icon="mdi-delete"
                  size="small"
                  variant="text"
                  color="error"
                  class="source-actions__icon"
                  @click="removeProfile(item)"
                />
              </AppRowActions>
            </template>
          </v-data-table>
        </SectionCard>
      </v-window-item>

      <v-window-item value="providers">
        <SectionCard
          class="labor-card"
          subtitle="Каталог сайтов и справочных провайдеров, доступных при заполнении источников труда."
        >
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
            class="labor-table"
          >
            <template #[`item.title`]="{ item }">
              <div class="reference-cell">
                <div class="reference-cell__title">{{ item.title || 'Без названия' }}</div>
                <div v-if="item.domain" class="reference-cell__meta">{{ item.domain }}</div>
              </div>
            </template>

            <template #[`item.domain`]="{ item }">
              <div class="reference-chip-wrap">
                <span class="reference-chip">{{ item.domain || '—' }}</span>
              </div>
            </template>

            <template #[`item.base_url`]="{ item }">
              <div class="reference-cell">
                <a
                  v-if="item.base_url"
                  :href="item.base_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="reference-link"
                >
                  {{ item.base_url }}
                </a>
                <div v-else class="reference-cell__text">—</div>
              </div>
            </template>

            <template #[`item.actions`]="{ item }">
              <AppRowActions class="source-actions">
                <v-btn
                  icon="mdi-pencil"
                  size="small"
                  variant="text"
                  color="primary"
                  class="source-actions__icon"
                  @click="openProviderDialog(item)"
                />
                <v-btn
                  icon="mdi-delete"
                  size="small"
                  variant="text"
                  color="error"
                  class="source-actions__icon"
                  @click="removeProvider(item)"
                />
              </AppRowActions>
            </template>
          </v-data-table>
        </SectionCard>
      </v-window-item>
    </v-window>

    <v-dialog v-model="sourceDialog" max-width="860" scrollable>
      <v-card class="labor-dialog-card labor-source-dialog">
        <v-card-title class="labor-source-dialog__title">
          <div>
            <div>{{ editingSource ? 'Редактировать источник' : 'Новый источник труда' }}</div>
            <div class="labor-source-dialog__subtitle">
              Источник труда, который можно открыть в деталях, привязать к проекту и использовать в обосновании.
            </div>
          </div>
        </v-card-title>
        <v-card-text class="labor-source-dialog__body pa-5">
          <v-alert v-if="sourceSubmitError" type="error" variant="tonal" class="mb-4" closable @click:close="sourceSubmitError = ''">
            {{ sourceSubmitError }}
          </v-alert>
          <v-form @submit.prevent="saveSource" class="labor-source-form">
            <section class="labor-form-section">
              <div class="labor-form-section__head">
                <div class="labor-form-section__title">Вакансия и контекст</div>
                <div class="labor-form-section__text">Основные сведения об источнике и его принадлежности к профилю работ.</div>
              </div>
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
                <v-col cols="12" md="4" class="labor-form-section__aside">
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
              </v-row>
            </section>

            <section class="labor-form-section">
              <div class="labor-form-section__head">
                <div class="labor-form-section__title">Ссылка и датировка</div>
                <div class="labor-form-section__text">Поля для привязки к исходной публикации и фиксации даты наблюдения.</div>
              </div>
              <v-row dense>
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
              </v-row>
            </section>

            <section class="labor-form-section">
              <div class="labor-form-section__head">
                <div class="labor-form-section__title">Ставка и расчёт</div>
                <div class="labor-form-section__text">Сохраните исходный текст зарплаты и при необходимости заполните нормализованные значения.</div>
              </div>
              <v-row dense>
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
              </v-row>
            </section>

            <section class="labor-form-section">
              <div class="labor-form-section__head">
                <div class="labor-form-section__title">Описание и примечания</div>
                <div class="labor-form-section__text">Текст вакансии и внутренние заметки для дальнейшей проверки источника.</div>
              </div>
              <v-row dense>
                <v-col cols="12">
                  <v-textarea v-model="sourceForm.vacancy_description" label="Описание вакансии" variant="outlined" density="compact" rows="4" />
                </v-col>
                <v-col cols="12">
                  <v-textarea v-model="sourceForm.note" label="Примечание" variant="outlined" density="compact" rows="2" />
                </v-col>
              </v-row>
            </section>
          </v-form>
        </v-card-text>
        <AppActionFooter class="labor-source-dialog__actions">
          <v-btn variant="text" @click="sourceDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.source" :disabled="!canSubmitSource" @click="saveSource">
            Сохранить
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-dialog v-model="providerDialog" max-width="520">
      <v-card class="labor-dialog-card labor-simple-dialog">
        <v-card-title class="labor-simple-dialog__title">
          <div>
            <div>{{ editingProvider ? 'Редактировать источник' : 'Новый источник' }}</div>
            <div class="labor-simple-dialog__subtitle">
              Справочник сайтов и провайдеров, доступных при создании источников труда.
            </div>
          </div>
        </v-card-title>
        <v-card-text class="labor-simple-dialog__body pa-5">
          <section class="labor-form-section labor-form-section--compact">
            <div class="labor-form-section__head">
              <div class="labor-form-section__title">Основные данные</div>
              <div class="labor-form-section__text">Название, домен и базовая ссылка для выбора в labor-форме.</div>
            </div>
            <v-text-field v-model="providerForm.title" label="Название" variant="outlined" density="compact" class="mb-3" />
            <v-text-field v-model="providerForm.domain" label="Домен" variant="outlined" density="compact" class="mb-3" />
            <v-text-field v-model="providerForm.base_url" label="Базовая ссылка" variant="outlined" density="compact" />
          </section>
        </v-card-text>
        <AppActionFooter class="labor-simple-dialog__actions">
          <v-btn variant="text" @click="providerDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.provider" @click="saveProvider">Сохранить</v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-dialog v-model="profileDialog" max-width="520">
      <v-card class="labor-dialog-card labor-simple-dialog">
        <v-card-title class="labor-simple-dialog__title">
          <div>
            <div>{{ editingProfile ? 'Редактировать профиль' : 'Новый профиль' }}</div>
            <div class="labor-simple-dialog__subtitle">
              Профиль работ для группировки источников и дальнейшего расчёта трудовых ставок.
            </div>
          </div>
        </v-card-title>
        <v-card-text class="labor-simple-dialog__body pa-5">
          <section class="labor-form-section labor-form-section--compact">
            <div class="labor-form-section__head">
              <div class="labor-form-section__title">Описание профиля</div>
              <div class="labor-form-section__text">Короткое название и пояснение для команды, работающей с labor-источниками.</div>
            </div>
            <v-text-field v-model="profileForm.title" label="Название" variant="outlined" density="compact" class="mb-3" />
            <v-textarea v-model="profileForm.description" label="Описание" variant="outlined" density="compact" rows="3" />
          </section>
        </v-card-text>
        <AppActionFooter class="labor-simple-dialog__actions">
          <v-btn variant="text" @click="profileDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="saving.profile" @click="saveProfile">Сохранить</v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <LaborEvidenceDetailsDialog v-model="detailsDialog" :source="detailsSource" @source-updated="handleSourceUpdated" />
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import AppTabs from '@/components/layout/AppTabs.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
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
const laborTabItems = [
  { value: 'sources', label: 'Источники' },
  { value: 'profiles', label: 'Профили' },
  { value: 'providers', label: 'Источники сайтов' },
]
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
  { title: 'Действия', key: 'actions', sortable: false, align: 'center' as const },
]

const providerHeaders = [
  { title: 'Название', key: 'title', sortable: false },
  { title: 'Домен', key: 'domain', sortable: false },
  { title: 'Базовая ссылка', key: 'base_url', sortable: false },
  { title: 'Действия', key: 'actions', sortable: false, align: 'center' as const },
]

const profileHeaders = [
  { title: 'Название', key: 'title', sortable: false },
  { title: 'Описание', key: 'description', sortable: false },
  { title: 'Действия', key: 'actions', sortable: false, align: 'center' as const },
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
.labor-tabs {
  border-radius: var(--ds-radius-14);
  background: rgba(var(--v-theme-surface-container-low), 0.9);
  padding: var(--ds-space-6);
}

.labor-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 96%, transparent);
}

.labor-table :deep(.v-table__wrapper) {
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background: rgba(var(--v-theme-surface), 0.96);
}

.labor-table :deep(thead th) {
  height: 52px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.68) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.94) !important;
}

.labor-table :deep(thead th .v-data-table-header__content) {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ds-text-secondary);
}

.labor-table :deep(tbody td) {
  vertical-align: top;
  border-bottom-color: rgba(var(--v-theme-outline-variant), 0.46) !important;
  padding-top: 14px !important;
  padding-bottom: 14px !important;
}

.labor-table :deep(tbody tr:last-child td) {
  border-bottom: 0 !important;
}

.labor-table :deep(.v-data-table-footer) {
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
  background: rgba(var(--v-theme-surface-container-lowest), 0.82);
}

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
  color: var(--ds-text-primary);
}

.section-head__text {
  margin-top: 4px;
  color: var(--ds-text-secondary);
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
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--ds-radius-18);
  overflow: hidden;
  background: rgba(var(--v-theme-surface-container-low), 0.84);
}

.profile-group-card--legacy {
  border-style: dashed;
  background: color-mix(in srgb, rgba(var(--v-theme-warning), 0.12) 65%, rgba(var(--v-theme-surface-container-high), 0.88));
}

.profile-group-card__head {
  padding: 16px 18px 10px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
  background: rgba(var(--v-theme-surface-container-lowest), 0.68);
}

.profile-group-card__title {
  font-size: 16px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--ds-text-primary);
}

.profile-group-card__count {
  font-weight: 500;
  color: var(--ds-text-secondary);
}

.profile-group-card__meta {
  margin-top: 4px;
  font-size: 13px;
  color: var(--ds-text-secondary);
}

.source-title-cell__title {
  font-weight: 600;
  line-height: 1.35;
  color: var(--ds-text-primary);
}

.source-title-cell__link {
  display: block;
  width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.source-title-cell__link:hover .source-title-cell__title,
.source-title-cell__link:focus-visible .source-title-cell__title {
  color: rgb(var(--v-theme-primary));
}

.source-title-cell__link:focus-visible {
  outline: 2px solid var(--ds-border-focus);
  outline-offset: 4px;
  border-radius: var(--ds-radius-8);
}

.source-title-cell__meta {
  margin-top: 2px;
  font-size: 12px;
  color: var(--ds-text-secondary);
}

.source-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-width: 96px;
  margin: 0 auto;
}

.source-actions__icon {
  border-radius: var(--ds-radius-full) !important;
}

.reference-cell {
  display: grid;
  gap: 4px;
}

.reference-cell__title {
  font-weight: 600;
  line-height: 1.35;
  color: var(--ds-text-primary);
}

.reference-cell__meta,
.reference-cell__text {
  font-size: 12px;
  line-height: 1.45;
  color: var(--ds-text-secondary);
}

.reference-chip-wrap {
  display: flex;
  align-items: center;
}

.reference-chip {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 10px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-full);
  background: rgba(var(--v-theme-surface-container-lowest), 0.86);
  font-size: 12px;
  font-weight: 500;
  line-height: 1;
  color: var(--ds-text-secondary);
}

.reference-link {
  color: var(--ds-text-primary);
  text-decoration: none;
  overflow-wrap: anywhere;
}

.reference-link:hover,
.reference-link:focus-visible {
  color: rgb(var(--v-theme-primary));
}

.labor-dialog-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-high) 94%, white 6%);
}

.labor-source-dialog__title {
  min-height: 80px;
  padding: var(--ds-space-16) var(--ds-space-20);
  border-bottom: 1px solid var(--ds-divider);
  background:
    linear-gradient(
      180deg,
      rgba(var(--v-theme-secondary-container), 0.28),
      rgba(var(--v-theme-surface-container-low), 0.92)
    );
}

.labor-source-dialog__subtitle {
  margin-top: 4px;
  font-size: 13px;
  font-weight: 400;
  line-height: 1.45;
  color: var(--ds-text-secondary);
}

.labor-source-dialog__body {
  background: rgba(var(--v-theme-surface-container-low), 0.56);
}

.labor-source-dialog__actions {
  border-top: 1px solid var(--ds-divider);
  background: rgba(var(--v-theme-surface-container-lowest), 0.72);
}

.labor-source-form {
  display: grid;
  gap: 16px;
}

.labor-form-section {
  padding: 16px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgba(var(--v-theme-surface-container-lowest), 0.82);
}

.labor-form-section--compact {
  padding: 18px;
}

.labor-form-section__head {
  margin-bottom: 14px;
}

.labor-form-section__title {
  font-size: 14px;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.labor-form-section__text {
  margin-top: 4px;
  font-size: 13px;
  line-height: 1.45;
  color: var(--ds-text-secondary);
}

.labor-form-section__aside {
  display: flex;
  align-items: center;
}

.labor-simple-dialog__title {
  min-height: 76px;
  padding: var(--ds-space-16) var(--ds-space-20);
  border-bottom: 1px solid var(--ds-divider);
  background:
    linear-gradient(
      180deg,
      rgba(var(--v-theme-secondary-container), 0.24),
      rgba(var(--v-theme-surface-container-low), 0.9)
    );
}

.labor-simple-dialog__subtitle {
  margin-top: 4px;
  font-size: 13px;
  font-weight: 400;
  line-height: 1.45;
  color: var(--ds-text-secondary);
}

.labor-simple-dialog__body {
  background: rgba(var(--v-theme-surface-container-low), 0.56);
}

.labor-simple-dialog__actions {
  border-top: 1px solid var(--ds-divider);
  background: rgba(var(--v-theme-surface-container-lowest), 0.72);
}

@media (max-width: 900px) {
  .section-head {
    flex-direction: column;
  }

  .source-actions {
    justify-content: flex-start;
    margin: 0;
  }

  .labor-source-dialog__title,
  .labor-source-dialog__body,
  .labor-source-dialog__actions,
  .labor-simple-dialog__title,
  .labor-simple-dialog__body,
  .labor-simple-dialog__actions {
    padding-left: var(--ds-space-16);
    padding-right: var(--ds-space-16);
  }

  .labor-form-section__aside {
    align-items: flex-start;
  }
}
</style>
