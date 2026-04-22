<template>
  <v-dialog :model-value="modelValue" max-width="980" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">
        <div>
          <div class="text-h6">Evidence для источника цены</div>
          <div class="text-body-2 text-medium-emphasis">
            {{ sourceTitle }}
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <v-row dense class="mb-4">
          <v-col cols="12" lg="6">
            <v-card variant="outlined">
              <v-card-title class="text-subtitle-1">Загрузить файл / изображение</v-card-title>
              <v-card-text class="pt-2">
                <v-select
                  v-model="fileForm.asset_type"
                  :items="uploadTypeItems"
                  item-title="label"
                  item-value="value"
                  label="Тип evidence"
                />
                <v-file-input
                  v-model="fileForm.file"
                  label="Файл"
                  show-size
                  prepend-icon="mdi-paperclip"
                  :error-messages="fieldErrors.file"
                />
                <v-text-field
                  v-model="fileForm.captured_at"
                  type="datetime-local"
                  label="Дата фиксации"
                  :error-messages="fieldErrors.captured_at"
                />
                <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                  Подходит для PDF, скриншотов, изображений и других файлов-обоснований.
                </v-alert>
              </v-card-text>
              <v-card-actions>
                <v-spacer />
                <v-btn color="primary" class="text-none" :loading="uploadingFile" @click="submitFileEvidence">
                  Загрузить
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>

          <v-col cols="12" lg="6">
            <v-card variant="outlined">
              <v-card-title class="text-subtitle-1">Добавить ссылку</v-card-title>
              <v-card-text class="pt-2">
                <v-text-field
                  v-model="linkForm.source_url"
                  label="URL *"
                  placeholder="https://..."
                  prepend-inner-icon="mdi-link-variant"
                  :error-messages="fieldErrors.source_url"
                />
                <v-text-field
                  v-model="linkForm.captured_at"
                  type="datetime-local"
                  label="Дата фиксации"
                  :error-messages="fieldErrors.captured_at"
                />
                <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                  Используйте link-evidence для внешних страниц, карточек товара и веб-обоснований.
                </v-alert>
              </v-card-text>
              <v-card-actions>
                <v-spacer />
                <v-btn color="primary" class="text-none" :loading="creatingLink" @click="submitLinkEvidence">
                  Добавить ссылку
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>

        <div class="d-flex align-center mb-2">
          <div class="text-subtitle-1">Текущие evidence assets</div>
          <v-spacer />
          <v-btn variant="text" class="text-none" :loading="loading" @click="loadAssets">Обновить</v-btn>
        </div>

        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
          Загружаем evidence assets…
        </v-alert>

        <v-alert
          v-else-if="assets.length === 0"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-3"
        >
          Для этого источника пока нет attached evidence. Добавьте файл, изображение или ссылку выше.
        </v-alert>

        <v-list v-else density="compact" class="border rounded">
          <v-list-item
            v-for="asset in assets"
            :key="asset.id"
            class="py-2"
          >
            <template #prepend>
              <v-icon size="small">{{ pricingEvidenceIcon(asset.asset_type) }}</v-icon>
            </template>
            <v-list-item-title>
              {{ pricingEvidenceLabel(asset.asset_type) }}
              <span v-if="asset.original_name"> · {{ asset.original_name }}</span>
            </v-list-item-title>
            <v-list-item-subtitle>
              <span v-if="asset.mime_type">{{ asset.mime_type }}</span>
              <span v-if="asset.file_size !== null"> · {{ formatFileSize(asset.file_size) }}</span>
              <span v-if="asset.captured_at"> · {{ formatDateTime(asset.captured_at) }}</span>
              <span v-if="asset.source_url"> · {{ asset.source_url }}</span>
            </v-list-item-subtitle>
            <template #append>
              <div class="d-flex align-center ga-1">
                <v-btn
                  v-if="asset.can_preview && asset.preview_url"
                  :href="asset.preview_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  size="x-small"
                  variant="text"
                  class="text-none"
                >
                  Предпросмотр
                </v-btn>
                <v-btn
                  v-if="asset.can_download && asset.download_url"
                  :href="asset.download_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  size="x-small"
                  variant="text"
                  class="text-none"
                >
                  Скачать
                </v-btn>
                <v-btn
                  v-else-if="asset.open_url && asset.access_kind === 'external'"
                  :href="asset.open_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  size="x-small"
                  variant="text"
                  class="text-none"
                >
                  Открыть
                </v-btn>
                <v-btn
                  size="x-small"
                  variant="text"
                  class="text-none"
                  color="error"
                  :loading="deletingAssetId === asset.id"
                  @click="confirmDelete(asset)"
                >
                  Удалить
                </v-btn>
              </div>
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Закрыть</v-btn>
      </v-card-actions>
    </v-card>

    <v-dialog v-model="showDeleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить evidence?</v-card-title>
        <v-card-text>
          Asset «{{ deletingAssetLabel }}» будет удален у текущего price source.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deletingAssetId !== null" @click="removeAsset">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  FinishedProductPriceEvidenceAssetCreatePayload,
  FinishedProductPriceEvidenceAssetDetails,
  FinishedProductPriceSource,
} from '@/api/finishedProductPricing'
import { finishedProductPricingApi } from '@/api/finishedProductPricing'
import {
  formatDateTime,
  formatFileSize,
  pricingEvidenceIcon,
  pricingEvidenceLabel,
} from './finishedProductPricingOptions'

type ValidationErrors = Record<string, string[]>

const props = defineProps<{
  modelValue: boolean
  source: FinishedProductPriceSource | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'changed'): void
}>()

const loading = ref(false)
const uploadingFile = ref(false)
const creatingLink = ref(false)
const deletingAssetId = ref<number | null>(null)
const error = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const assets = ref<FinishedProductPriceEvidenceAssetDetails[]>([])
const showDeleteDialog = ref(false)
const deletingAsset = ref<FinishedProductPriceEvidenceAssetDetails | null>(null)

const fileForm = reactive({
  asset_type: 'file' as 'screenshot' | 'file' | 'image',
  file: null as File | File[] | null,
  captured_at: '',
})

const linkForm = reactive({
  source_url: '',
  captured_at: '',
})

const uploadTypeItems = [
  { value: 'file', label: 'Файл / документ' },
  { value: 'image', label: 'Изображение' },
  { value: 'screenshot', label: 'Скриншот' },
]

const sourceTitle = computed(() => {
  if (!props.source) return 'Источник не выбран'

  const parts = [
    props.source.supplier.name || 'Без поставщика',
    props.source.article || null,
    props.source.description || null,
  ].filter(Boolean)

  return parts.join(' · ')
})

const deletingAssetLabel = computed(() => {
  if (!deletingAsset.value) return 'Evidence asset'
  return deletingAsset.value.original_name || pricingEvidenceLabel(deletingAsset.value.asset_type)
})

function resetErrors() {
  error.value = null
  fieldErrors.value = {}
}

function extractFieldErrors(err: any): ValidationErrors {
  return err?.response?.data?.errors ?? {}
}

function resetForms() {
  fileForm.asset_type = 'file'
  fileForm.file = null
  fileForm.captured_at = ''
  linkForm.source_url = ''
  linkForm.captured_at = ''
}

async function loadAssets() {
  if (!props.source) return

  loading.value = true
  resetErrors()

  try {
    const response = await finishedProductPricingApi.listEvidenceAssets(props.source.id)
    assets.value = response.data.assets
  } catch (err: any) {
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось загрузить evidence assets'
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (opened) => {
    if (!opened) return
    resetForms()
    loadAssets()
  },
)

async function submitFileEvidence() {
  if (!props.source) return

  uploadingFile.value = true
  resetErrors()

  try {
    const selectedFile = Array.isArray(fileForm.file) ? fileForm.file[0] ?? null : fileForm.file
    const payload: FinishedProductPriceEvidenceAssetCreatePayload = {
      asset_type: fileForm.asset_type,
      file: selectedFile,
      captured_at: fileForm.captured_at || null,
    }

    await finishedProductPricingApi.createEvidenceAsset(props.source.id, payload)
    resetForms()
    await loadAssets()
    emit('changed')
  } catch (err: any) {
    fieldErrors.value = extractFieldErrors(err)
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось загрузить evidence asset'
  } finally {
    uploadingFile.value = false
  }
}

async function submitLinkEvidence() {
  if (!props.source) return

  creatingLink.value = true
  resetErrors()

  try {
    const payload: FinishedProductPriceEvidenceAssetCreatePayload = {
      asset_type: 'link',
      source_url: linkForm.source_url.trim() || null,
      captured_at: linkForm.captured_at || null,
    }

    await finishedProductPricingApi.createEvidenceAsset(props.source.id, payload)
    resetForms()
    await loadAssets()
    emit('changed')
  } catch (err: any) {
    fieldErrors.value = extractFieldErrors(err)
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось добавить ссылку'
  } finally {
    creatingLink.value = false
  }
}

function confirmDelete(asset: FinishedProductPriceEvidenceAssetDetails) {
  deletingAsset.value = asset
  showDeleteDialog.value = true
}

async function removeAsset() {
  if (!deletingAsset.value) return

  deletingAssetId.value = deletingAsset.value.id
  resetErrors()

  try {
    await finishedProductPricingApi.deleteEvidenceAsset(deletingAsset.value.id)
    showDeleteDialog.value = false
    deletingAsset.value = null
    await loadAssets()
    emit('changed')
  } catch (err: any) {
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось удалить evidence asset'
  } finally {
    deletingAssetId.value = null
  }
}
</script>
