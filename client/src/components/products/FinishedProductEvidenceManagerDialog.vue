<template>
  <v-dialog :model-value="modelValue" max-width="980" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card class="fp-evidence-dialog-card">
      <v-card-title class="fp-evidence-dialog-card__header">
        <div>
          <div class="fp-evidence-dialog-card__title">Доказательства источника цены</div>
          <div class="fp-evidence-dialog-card__subtitle">
            {{ sourceTitle }}
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text class="fp-evidence-dialog-card__content">
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <v-row dense class="fp-evidence-upload-grid">
          <v-col cols="12" lg="6">
            <v-card
              variant="outlined"
              class="fp-evidence-input-card"
              :class="{ 'border-primary': pasteActive }"
              tabindex="0"
              @paste="handlePaste"
            >
              <v-card-title class="fp-evidence-input-card__title">Файл или скриншот</v-card-title>
              <v-card-text class="fp-evidence-input-card__content">
                <v-file-input
                  v-model="fileForm.file"
                  accept=".pdf,.xls,.xlsx,.doc,.docx,image/*"
                  label="Выберите файл"
                  show-size
                  prepend-icon="mdi-paperclip"
                  :error-messages="fieldErrors.file"
                />
                <v-alert type="info" variant="tonal" density="compact">
                  Можно загрузить PDF, Excel, Word, изображение или вставить скриншот из буфера обмена Ctrl+V.
                </v-alert>
              </v-card-text>
              <v-card-actions class="fp-evidence-input-card__actions">
                <v-spacer />
                <v-btn color="primary" class="text-none" :loading="uploadingFile" @click="submitFileEvidence()">
                  Добавить файл
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>

          <v-col cols="12" lg="6">
            <v-card variant="outlined" class="fp-evidence-input-card">
              <v-card-title class="fp-evidence-input-card__title">Добавить ссылку</v-card-title>
              <v-card-text class="fp-evidence-input-card__content">
                <v-text-field
                  v-model="linkForm.source_url"
                  label="Ссылка *"
                  placeholder="fabfas.ru или https://fabfas.ru"
                  prepend-inner-icon="mdi-link-variant"
                  :error-messages="fieldErrors.source_url"
                  @blur="normalizeLinkField"
                />
                <v-alert type="info" variant="tonal" density="compact">
                  Ссылка подойдёт для страницы товара, карточки поставщика или другого открытого подтверждения цены.
                </v-alert>
              </v-card-text>
              <v-card-actions class="fp-evidence-input-card__actions">
                <v-spacer />
                <v-btn color="primary" class="text-none" :loading="creatingLink" @click="submitLinkEvidence">
                  Добавить ссылку
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>

        <div class="fp-evidence-section-bar">
          <div class="text-subtitle-1">Добавленные доказательства</div>
          <v-spacer />
          <v-btn variant="text" class="text-none" :loading="loading" @click="loadAssets">Обновить</v-btn>
        </div>

        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
          Загружаем доказательства…
        </v-alert>

        <v-alert
          v-else-if="assets.length === 0"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-3"
        >
          Доказательства не добавлены. Перед формированием итогового отчёта рекомендуется прикрепить файл, скриншот или ссылку.
        </v-alert>

        <v-list v-else density="compact" class="fp-evidence-list">
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
              <span v-if="asset.file_size !== null">{{ formatFileSize(asset.file_size) }}</span>
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

      <v-card-actions class="fp-evidence-dialog-card__actions">
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Закрыть</v-btn>
      </v-card-actions>
    </v-card>

    <v-dialog v-model="showDeleteDialog" max-width="420">
      <v-card class="fp-evidence-delete-card">
        <v-card-title class="fp-evidence-delete-card__title">Удалить доказательство?</v-card-title>
        <v-card-text class="fp-evidence-delete-card__content">
          «{{ deletingAssetLabel }}» будет удалено у текущего источника цены.
        </v-card-text>
        <v-card-actions class="fp-evidence-dialog-card__actions">
          <v-spacer />
          <v-btn @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn color="error" :loading="deletingAssetId !== null" @click="removeAsset">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
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
const pasteActive = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const assets = ref<FinishedProductPriceEvidenceAssetDetails[]>([])
const showDeleteDialog = ref(false)
const deletingAsset = ref<FinishedProductPriceEvidenceAssetDetails | null>(null)

const fileForm = reactive({
  file: null as File | File[] | null,
})

const linkForm = reactive({
  source_url: '',
})

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
  if (!deletingAsset.value) return 'Доказательство'
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
  fileForm.file = null
  linkForm.source_url = ''
}

function getSelectedFile(): File | null {
  return Array.isArray(fileForm.file) ? fileForm.file[0] ?? null : fileForm.file
}

function inferAssetType(file: File | null, isPasted = false): 'screenshot' | 'file' | 'image' {
  if (isPasted) return 'screenshot'
  if (file?.type?.startsWith('image/')) return 'image'
  return 'file'
}

function normalizeEvidenceUrl(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (/^https?:\/\//i.test(trimmed)) return trimmed
  return `https://${trimmed}`
}

function normalizeLinkField() {
  linkForm.source_url = normalizeEvidenceUrl(linkForm.source_url)
}

async function loadAssets() {
  if (!props.source) return

  loading.value = true
  resetErrors()

  try {
    const response = await finishedProductPricingApi.listEvidenceAssets(props.source.id)
    assets.value = response.data.assets
  } catch (err: any) {
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось загрузить доказательства'
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

async function submitFileEvidence(fileOverride?: File | null, isPasted = false) {
  if (!props.source) return

  uploadingFile.value = true
  resetErrors()

  try {
    const selectedFile = fileOverride ?? getSelectedFile()
    const payload: FinishedProductPriceEvidenceAssetCreatePayload = {
      asset_type: inferAssetType(selectedFile, isPasted),
      file: selectedFile,
    }

    await finishedProductPricingApi.createEvidenceAsset(props.source.id, payload)
    resetForms()
    await loadAssets()
    emit('changed')
  } catch (err: any) {
    fieldErrors.value = extractFieldErrors(err)
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось загрузить доказательство'
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
      source_url: normalizeEvidenceUrl(linkForm.source_url) || null,
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

function findPastedImage(event: ClipboardEvent): File | null {
  const items = event.clipboardData?.items
  if (!items?.length) return null

  for (const item of items) {
    if (item.kind === 'file' && item.type.startsWith('image/')) {
      return item.getAsFile()
    }
  }

  return null
}

function handlePaste(event: ClipboardEvent) {
  const file = findPastedImage(event)
  if (!file) return

  event.preventDefault()
  pasteActive.value = true
  window.setTimeout(() => {
    pasteActive.value = false
  }, 700)
  void submitFileEvidence(file, true)
}

function onWindowPaste(event: ClipboardEvent) {
  if (!props.modelValue || event.defaultPrevented) return
  handlePaste(event)
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
    error.value = err?.response?.data?.message ?? err?.message ?? 'Не удалось удалить доказательство'
  } finally {
    deletingAssetId.value = null
  }
}

watch(
  () => props.modelValue,
  (opened) => {
    if (opened) {
      window.addEventListener('paste', onWindowPaste)
    } else {
      window.removeEventListener('paste', onWindowPaste)
    }
  },
)

onBeforeUnmount(() => {
  window.removeEventListener('paste', onWindowPaste)
})
</script>

<style scoped>
.fp-evidence-dialog-card,
.fp-evidence-delete-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.76);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background: rgba(var(--v-theme-surface-container-low), 0.98);
}

.fp-evidence-dialog-card__header {
  display: flex;
  align-items: flex-start;
  gap: var(--ds-space-12);
  padding: 18px 20px 14px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.6);
}

.fp-evidence-dialog-card__title,
.fp-evidence-delete-card__title {
  font-size: 1.125rem;
  line-height: 1.3;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.fp-evidence-dialog-card__subtitle {
  margin-top: 4px;
  color: var(--ds-text-secondary);
}

.fp-evidence-dialog-card__content {
  display: grid;
  gap: var(--ds-space-14);
  padding: 20px !important;
}

.fp-evidence-upload-grid {
  margin: 0 !important;
}

.fp-evidence-input-card {
  height: 100%;
  border-color: rgba(var(--v-theme-outline-variant), 0.68) !important;
  background: rgba(var(--v-theme-surface), 0.96) !important;
}

.fp-evidence-input-card__title {
  min-height: 48px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.52);
  font-size: 1rem;
  font-weight: 700;
}

.fp-evidence-input-card__content {
  display: grid;
  gap: var(--ds-space-12);
  padding-top: var(--ds-space-14) !important;
}

.fp-evidence-input-card__actions {
  padding: 8px 16px 14px !important;
}

.fp-evidence-section-bar {
  display: flex;
  align-items: center;
  gap: var(--ds-space-12);
  padding-top: var(--ds-space-4);
}

.fp-evidence-list {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.68);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface), 0.96);
}

.fp-evidence-dialog-card__actions {
  padding: 12px 20px 18px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
}

.fp-evidence-delete-card__content {
  padding: 0 20px 20px !important;
}
</style>
