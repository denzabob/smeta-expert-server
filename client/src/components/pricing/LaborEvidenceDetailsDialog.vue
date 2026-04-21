<template>
  <v-dialog
    :model-value="modelValue"
    max-width="980"
    scrollable
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <v-card v-if="currentSource">
      <v-card-title class="d-flex align-center justify-space-between flex-wrap ga-3">
        <div>
          <div class="text-h6">{{ currentSource.vacancy_title || currentSource.source_title || 'Источник обоснования труда' }}</div>
          <div class="text-body-2 text-medium-emphasis">
            {{ currentSource.employer_name || 'Работодатель не указан' }}
          </div>
        </div>
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-divider />

      <v-card-text class="pa-5">
        <v-alert
          v-if="feedback.message"
          :type="feedback.type"
          variant="tonal"
          closable
          class="mb-4"
          @click:close="clearFeedback"
        >
          {{ feedback.message }}
        </v-alert>

        <v-alert
          v-if="!hasScreenshot"
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          Скриншот не приложен — такой источник может не попасть в итоговое обоснование.
        </v-alert>

        <div class="completeness-grid mb-5">
          <v-chip :color="hasScreenshot ? 'success' : 'warning'" variant="tonal" size="small">
            {{ hasScreenshot ? 'Скриншот' : 'Нет' }}
          </v-chip>
          <v-chip :color="hasSalaryData ? 'success' : 'warning'" variant="tonal" size="small">
            {{ hasSalaryData ? 'Есть данные по ставке' : 'Нет данных по ставке' }}
          </v-chip>
          <v-chip :color="hasVacancyTitle ? 'success' : 'warning'" variant="tonal" size="small">
            {{ hasVacancyTitle ? 'Название вакансии заполнено' : 'Нет названия вакансии' }}
          </v-chip>
        </div>

        <v-row dense>
          <v-col cols="12" md="6">
            <div class="detail-list">
              <div class="detail-row">
                <span class="detail-label">Источник</span>
                <span class="detail-value">{{ currentSource.provider?.title || sourceDomain }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Домен</span>
                <span class="detail-value">{{ sourceDomain }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Регион</span>
                <span class="detail-value">{{ laborRegionLabel(currentSource.region) }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Профиль работ</span>
                <span class="detail-value">{{ laborProfileOf(currentSource)?.title || 'Не задан' }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Зарплата / ставка</span>
                <span class="detail-value">{{ salaryLabel }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Расчётная ставка за час</span>
                <span class="detail-value">{{ hourlyRateLabel }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Часов в месяц</span>
                <span class="detail-value">{{ currentSource.hours_per_month || '—' }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Дата источника</span>
                <span class="detail-value">{{ dateLabel(currentSource.source_date) }}</span>
              </div>
              <div class="detail-row">
                <span class="detail-label">Ссылка</span>
                <span class="detail-value">
                  <a :href="currentSource.source_url" target="_blank" rel="noopener noreferrer">{{ currentSource.source_url }}</a>
                </span>
              </div>
            </div>
          </v-col>

          <v-col cols="12" md="6">
            <div class="upload-card">
              <div class="upload-card__head">
                <div>
                  <div class="asset-title">Файлы подтверждения</div>
                  <div class="text-body-2 text-medium-emphasis">
                    Загрузите скриншот вакансии или PDF-файл с подтверждением.
                  </div>
                </div>
                <v-btn
                  color="primary"
                  variant="flat"
                  prepend-icon="mdi-upload"
                  :loading="uploading"
                  @click="openFilePicker"
                >
                  Загрузить файл
                </v-btn>
                <input
                  ref="fileInput"
                  type="file"
                  class="d-none"
                  accept="image/png,image/jpeg,application/pdf"
                  @change="handleFileSelect"
                >
              </div>
              <div class="text-caption text-medium-emphasis mt-2">
                Поддерживаются PNG, JPEG и PDF до 10 МБ.
              </div>
            </div>

            <div class="asset-section mt-5">
              <div class="asset-title">Скриншоты</div>
              <div v-if="screenshotAssets.length" class="screenshot-grid">
                <div
                  v-for="asset in screenshotAssets"
                  :key="asset.id"
                  class="shot-card"
                >
                  <a
                    :href="laborAssetUrl(asset)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="shot-link"
                  >
                    <img :src="laborAssetUrl(asset)" :alt="asset.original_filename || 'Скриншот'" class="shot-image" />
                  </a>
                  <div class="shot-actions">
                    <v-btn
                      size="x-small"
                      variant="text"
                      color="primary"
                      :href="laborAssetUrl(asset)"
                      target="_blank"
                    >
                      Открыть
                    </v-btn>
                    <v-btn
                      size="x-small"
                      variant="text"
                      color="error"
                      :loading="deletingAssetId === asset.id"
                      @click="removeAsset(asset)"
                    >
                      Удалить
                    </v-btn>
                  </div>
                </div>
              </div>
              <div v-else class="text-body-2 text-medium-emphasis">
                Скриншоты ещё не загружены.
              </div>
            </div>

            <div class="asset-section mt-5">
              <div class="asset-title">Документы</div>
              <div v-if="documentAssets.length" class="document-list">
                <div
                  v-for="asset in documentAssets"
                  :key="asset.id"
                  class="document-row"
                >
                  <a
                    :href="laborAssetUrl(asset)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="document-link"
                  >
                    <v-icon size="18">mdi-file-document-outline</v-icon>
                    <span>{{ asset.original_filename || 'Документ' }}</span>
                  </a>
                  <div class="document-actions">
                    <v-btn
                      size="x-small"
                      variant="text"
                      color="primary"
                      :href="laborAssetUrl(asset)"
                      target="_blank"
                    >
                      Скачать
                    </v-btn>
                    <v-btn
                      size="x-small"
                      variant="text"
                      color="error"
                      :loading="deletingAssetId === asset.id"
                      @click="removeAsset(asset)"
                    >
                      Удалить
                    </v-btn>
                  </div>
                </div>
              </div>
              <div v-else class="text-body-2 text-medium-emphasis">
                Документы ещё не загружены.
              </div>
            </div>
          </v-col>
        </v-row>

        <div v-if="currentSource.note" class="mt-5">
          <div class="asset-title">Примечание</div>
          <div class="detail-text">{{ currentSource.note }}</div>
        </div>

        <div v-if="normalizedDescription" class="mt-5">
          <v-btn
            variant="text"
            color="primary"
            class="details-toggle-btn px-0"
            @click="descriptionExpanded = !descriptionExpanded"
          >
            {{ descriptionExpanded ? 'Скрыть описание' : 'Подробнее...' }}
          </v-btn>

          <div v-if="descriptionExpanded" class="mt-3">
            <div class="asset-title">Описание вакансии</div>
            <div class="detail-text">
              {{ normalizedDescription }}
            </div>
          </div>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  laborAssetUrl,
  laborCurrencySymbol,
  laborEvidenceApi,
  laborEvidenceRecordOf,
  laborFormatMoney,
  laborNormalizedDescription,
  laborProfileOf,
  laborRegionLabel,
  laborSourceDomain,
  laborSourceRateLabel,
  type LaborEvidenceAsset,
  type LaborEvidenceRecord,
  type LaborEvidenceSource,
} from '@/api/laborEvidence'

const props = defineProps<{
  modelValue: boolean
  source: LaborEvidenceSource | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'source-updated', value: LaborEvidenceSource): void
}>()

const currentSource = ref<LaborEvidenceSource | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const deletingAssetId = ref<number | null>(null)
const descriptionExpanded = ref(false)
const feedback = reactive<{
  type: 'success' | 'error' | 'warning'
  message: string
}>({
  type: 'success',
  message: '',
})

watch(
  () => [props.modelValue, props.source?.id],
  async ([isOpen, sourceId]) => {
    currentSource.value = props.source ? cloneSource(props.source) : null
    clearFeedback()
    descriptionExpanded.value = false

    if (isOpen && sourceId) {
      await refreshSource()
    }
  },
  { immediate: true },
)

const assets = computed<LaborEvidenceAsset[]>(() => {
  return laborEvidenceRecordOf(currentSource.value as LaborEvidenceSource)?.assets || []
})

const screenshotAssets = computed(() => assets.value.filter(asset => asset.asset_type === 'screenshot'))
const documentAssets = computed(() => assets.value.filter(asset => asset.asset_type !== 'screenshot'))
const hasScreenshot = computed(() => screenshotAssets.value.length > 0)
const hasVacancyTitle = computed(() => Boolean(currentSource.value?.vacancy_title?.trim()))
const hasSalaryData = computed(() => {
  const source = currentSource.value
  if (!source) return false

  return Boolean(
    source.salary_raw_text ||
    source.salary_value ||
    source.salary_value_min ||
    source.salary_value_max ||
    source.derived_hourly_rate,
  )
})

const salaryLabel = computed(() => {
  const source = currentSource.value
  if (!source) return '—'
  return laborSourceRateLabel(source)
})

const hourlyRateLabel = computed(() => {
  const source = currentSource.value
  if (!source?.derived_hourly_rate) return '—'
  return `${laborFormatMoney(source.derived_hourly_rate)} ${laborCurrencySymbol(source.currency)} / ч`
})

const normalizedDescription = computed(() => {
  const source = currentSource.value
  if (!source) return ''

  return laborNormalizedDescription(source)
})

const sourceDomain = computed(() => {
  const source = currentSource.value
  if (!source?.source_url) return '—'

  return laborSourceDomain(source)
})

function cloneSource(source: LaborEvidenceSource): LaborEvidenceSource {
  return {
    ...source,
    provider: source.provider ? { ...source.provider } : source.provider,
    labor_profile: source.labor_profile ? { ...source.labor_profile } : source.labor_profile,
    laborProfile: source.laborProfile ? { ...source.laborProfile } : source.laborProfile,
    region: source.region ? { ...source.region } : source.region,
    evidence_record: source.evidence_record
      ? { ...source.evidence_record, assets: [...(source.evidence_record.assets || [])] }
      : source.evidence_record,
    evidenceRecord: source.evidenceRecord
      ? { ...source.evidenceRecord, assets: [...(source.evidenceRecord.assets || [])] }
      : source.evidenceRecord,
  }
}

function clearFeedback() {
  feedback.message = ''
}

function setFeedback(type: 'success' | 'error' | 'warning', message: string) {
  feedback.type = type
  feedback.message = message
}

function dateLabel(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('ru-RU')
}

function ensureEvidenceRecord(source: LaborEvidenceSource): LaborEvidenceRecord {
  const assetsList = [...(source.evidenceRecord?.assets || source.evidence_record?.assets || [])]
  const existing = source.evidenceRecord || source.evidence_record

  const record: LaborEvidenceRecord = existing
    ? { ...existing, assets: assetsList }
    : {
        id: source.evidence_record_id || 0,
        uuid: '',
        source_url: source.source_url,
        source_domain: null,
        observed_price: null,
        currency: source.currency || 'RUB',
        observed_at: source.source_date,
        capture_method: source.captured_via || 'manual',
        verification_status: source.verification_status || 'pending',
        assets: assetsList,
      }

  source.evidenceRecord = record
  source.evidence_record = record

  return record
}

async function refreshSource() {
  if (!currentSource.value?.id) return

  const [freshSource, assetsList] = await Promise.all([
    laborEvidenceApi.getSource(currentSource.value.id),
    laborEvidenceApi.listAssets(currentSource.value.id),
  ])

  const nextSource = cloneSource(freshSource)
  const record = ensureEvidenceRecord(nextSource)
  record.assets = assetsList

  currentSource.value = nextSource
  emit('source-updated', nextSource)
}

function openFilePicker() {
  fileInput.value?.click()
}

async function handleFileSelect(event: Event) {
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  if (!file || !currentSource.value?.id) return

  if (file.size > 10 * 1024 * 1024) {
    setFeedback('error', 'Файл слишком большой. Допустимый размер — до 10 МБ.')
    if (input) input.value = ''
    return
  }

  const type = detectAssetType(file)
  if (!type) {
    setFeedback('error', 'Поддерживаются только PNG, JPEG и PDF.')
    if (input) input.value = ''
    return
  }

  uploading.value = true
  clearFeedback()

  try {
    await laborEvidenceApi.uploadAsset(currentSource.value.id, { file, type })
    await refreshSource()
    setFeedback('success', type === 'screenshot' ? 'Скриншот успешно загружен.' : 'Документ успешно загружен.')
  } catch (error: any) {
    const message =
      error?.response?.data?.message ||
      'Не удалось загрузить файл. Проверьте формат и размер и попробуйте ещё раз.'
    setFeedback('error', message)
  } finally {
    uploading.value = false
    if (input) input.value = ''
  }
}

async function removeAsset(asset: LaborEvidenceAsset) {
  if (!currentSource.value?.id) return
  if (!window.confirm(`Удалить файл "${asset.original_filename || 'без названия'}"?`)) return

  deletingAssetId.value = asset.id
  clearFeedback()

  try {
    await laborEvidenceApi.deleteAsset(currentSource.value.id, asset.id)
    await refreshSource()
    setFeedback('success', 'Файл удалён.')
  } catch (error: any) {
    const message = error?.response?.data?.message || 'Не удалось удалить файл.'
    setFeedback('error', message)
  } finally {
    deletingAssetId.value = null
  }
}

function detectAssetType(file: File): 'screenshot' | 'document' | null {
  if (file.type === 'image/png' || file.type === 'image/jpeg') return 'screenshot'
  if (file.type === 'application/pdf') return 'document'

  const lowerName = file.name.toLowerCase()
  if (lowerName.endsWith('.png') || lowerName.endsWith('.jpg') || lowerName.endsWith('.jpeg')) return 'screenshot'
  if (lowerName.endsWith('.pdf')) return 'document'

  return null
}
</script>

<style scoped>
.completeness-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.detail-list {
  display: grid;
  gap: 10px;
}

.detail-row {
  display: grid;
  gap: 4px;
}

.detail-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.detail-value {
  font-size: 14px;
  color: rgba(0, 0, 0, 0.88);
  overflow-wrap: anywhere;
}

.upload-card {
  padding: 14px 16px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 16px;
  background: #fafafa;
}

.upload-card__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.asset-section {
  display: grid;
  gap: 10px;
}

.asset-title {
  font-size: 14px;
  font-weight: 600;
}

.screenshot-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
}

.shot-card {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 12px;
  overflow: hidden;
  background: #f7f7f7;
}

.shot-link {
  display: block;
}

.shot-image {
  display: block;
  width: 100%;
  height: 150px;
  object-fit: cover;
}

.shot-actions {
  display: flex;
  justify-content: space-between;
  padding: 8px 10px;
}

.document-list {
  display: grid;
  gap: 8px;
}

.document-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 12px;
}

.document-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: inherit;
  text-decoration: none;
  overflow-wrap: anywhere;
}

.document-actions {
  display: flex;
  gap: 4px;
}

.detail-text {
  white-space: pre-wrap;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.8);
}

.details-toggle-btn {
  min-width: auto;
  font-weight: 600;
  text-transform: none;
}

@media (max-width: 760px) {
  .upload-card__head,
  .document-row {
    flex-direction: column;
    align-items: stretch;
  }

  .document-actions {
    justify-content: flex-end;
  }
}
</style>
