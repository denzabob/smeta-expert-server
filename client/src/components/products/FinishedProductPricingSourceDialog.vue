<template>
  <v-dialog :model-value="modelValue" max-width="860" persistent @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">
        <div>
          <div class="text-h6">{{ isEditMode ? 'Редактирование источника цены' : 'Новый источник цены' }}</div>
          <div class="text-body-2 text-medium-emphasis">
            Источник привязывается к выбранной фасадной спецификации и участвует в новой агрегированной цене.
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text>
        <v-alert v-if="error && !hasFieldErrors" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <v-row dense>
          <v-col cols="12" md="5">
            <v-autocomplete
              v-model="form.supplier_id"
              :items="supplierItems"
              item-title="name"
              item-value="id"
              label="Поставщик"
              clearable
              :loading="loadingSuppliers"
              :error-messages="fieldErrors.supplier_id"
              hint="Inline создание поставщика подключим отдельным блоком."
              persistent-hint
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="form.source_kind"
              :items="finishedProductPriceSourceKindItems"
              item-title="label"
              item-value="value"
              label="Тип источника *"
              :error-messages="fieldErrors.source_kind"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-select
              v-model="form.status"
              :items="finishedProductPriceSourceStatusItems"
              item-title="label"
              item-value="value"
              label="Статус"
              :error-messages="fieldErrors.status"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="6">
            <v-autocomplete
              v-model="selectedPriceListId"
              :items="priceListItems"
              item-title="label"
              item-value="id"
              label="Прайс-лист"
              clearable
              :disabled="!form.supplier_id"
              :loading="loadingPriceLists"
              :error-messages="fieldErrors.price_list_version_id"
            />
          </v-col>
          <v-col cols="12" md="6">
            <v-autocomplete
              v-model="form.price_list_version_id"
              :items="priceListVersionItems"
              item-title="label"
              item-value="id"
              label="Версия прайса"
              clearable
              :disabled="!selectedPriceListId"
              :loading="loadingVersions"
              :error-messages="fieldErrors.price_list_version_id"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="4">
            <v-text-field
              v-model.number="form.source_price"
              type="number"
              min="0.01"
              step="0.01"
              label="Цена источника *"
              :error-messages="fieldErrors.source_price"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model="form.source_unit"
              label="Ед. изм."
              placeholder="м², шт, лист"
              :error-messages="fieldErrors.source_unit"
            />
          </v-col>
          <v-col cols="12" md="2">
            <v-text-field
              v-model.number="form.conversion_factor_to_m2"
              type="number"
              min="0.000001"
              step="0.000001"
              label="Коэф. к м²"
              :error-messages="fieldErrors.conversion_factor_to_m2"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model.number="form.price_per_m2_normalized"
              type="number"
              min="0.01"
              step="0.01"
              label="Цена м² (опц.)"
              :error-messages="fieldErrors.price_per_m2_normalized"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="6">
            <v-text-field
              v-model="form.captured_at"
              type="datetime-local"
              label="Дата фиксации"
              :error-messages="fieldErrors.captured_at"
            />
          </v-col>
          <v-col cols="12" md="6">
            <v-text-field
              v-model="form.effective_date"
              type="date"
              label="Дата актуальности"
              :error-messages="fieldErrors.effective_date"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="4">
            <v-text-field v-model="form.article" label="Артикул" :error-messages="fieldErrors.article" />
          </v-col>
          <v-col cols="12" md="4">
            <v-text-field v-model="form.category" label="Категория" :error-messages="fieldErrors.category" />
          </v-col>
          <v-col cols="12" md="4">
            <v-text-field
              v-if="form.status === 'stale'"
              v-model="form.stale_reason"
              label="Причина устаревания"
              :error-messages="fieldErrors.stale_reason"
            />
          </v-col>
        </v-row>

        <v-text-field
          v-model="form.description"
          label="Описание"
          :error-messages="fieldErrors.description"
        />

        <v-textarea
          v-model="form.notes"
          label="Примечания"
          rows="3"
          auto-grow
          :error-messages="fieldErrors.notes"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Отмена</v-btn>
        <v-btn color="primary" :loading="saving" @click="submit">
          {{ isEditMode ? 'Сохранить' : 'Создать' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { PriceList, PriceListVersion } from '@/api/priceLists'
import { priceListsApi } from '@/api/priceLists'
import type { Supplier } from '@/api/suppliers'
import { suppliersApi } from '@/api/suppliers'
import type { FinishedProductPriceSource, FinishedProductPriceSourcePayload } from '@/api/finishedProductPricing'
import { finishedProductPricingApi } from '@/api/finishedProductPricing'
import {
  finishedProductPriceSourceKindItems,
  finishedProductPriceSourceStatusItems,
  formatDate,
} from './finishedProductPricingOptions'

type ValidationErrors = Record<string, string[]>

const props = defineProps<{
  modelValue: boolean
  specificationId: number
  source?: FinishedProductPriceSource | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'saved', source: FinishedProductPriceSource): void
}>()

const loadingSuppliers = ref(false)
const loadingPriceLists = ref(false)
const loadingVersions = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})

const supplierItems = ref<Supplier[]>([])
const priceLists = ref<PriceList[]>([])
const priceListVersions = ref<PriceListVersion[]>([])
const selectedPriceListId = ref<number | null>(null)

const isEditMode = computed(() => !!props.source?.id)
const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)

const emptyForm = (): FinishedProductPriceSourcePayload => ({
  supplier_id: null,
  source_kind: 'manual_entry',
  price_list_version_id: null,
  source_price: null,
  source_unit: 'м²',
  conversion_factor_to_m2: null,
  price_per_m2_normalized: null,
  captured_at: '',
  effective_date: '',
  article: '',
  category: '',
  description: '',
  status: 'active',
  stale_reason: '',
  notes: '',
  metadata: null,
})

const form = reactive<FinishedProductPriceSourcePayload>(emptyForm())

const priceListItems = computed(() =>
  priceLists.value.map((item) => ({
    id: item.id,
    label: item.name,
  })),
)

const priceListVersionItems = computed(() =>
  priceListVersions.value.map((item) => ({
    id: item.id,
    label: buildVersionLabel(item),
  })),
)

function resetFieldErrors() {
  fieldErrors.value = {}
}

function extractFieldErrors(error: any): ValidationErrors {
  return error?.response?.data?.errors ?? {}
}

function buildVersionLabel(version: PriceListVersion) {
  const parts = [
    version.original_filename || version.manual_label || `Версия #${version.id}`,
    version.effective_date ? `акт. ${formatDate(version.effective_date)}` : null,
    version.status ? `(${version.status})` : null,
  ].filter(Boolean)

  return parts.join(' · ')
}

function syncForm() {
  Object.assign(form, emptyForm(), {
    ...props.source,
    supplier_id: props.source?.supplier_id ?? props.source?.supplier?.id ?? null,
    source_kind: props.source?.source_kind ?? 'manual_entry',
    source_price: props.source?.source_price ?? null,
    source_unit: props.source?.source_unit ?? 'м²',
    conversion_factor_to_m2: props.source?.conversion_factor_to_m2 ?? null,
    price_per_m2_normalized: props.source?.price_per_m2_normalized ?? null,
    captured_at: normalizeDateTimeInput(props.source?.captured_at ?? null),
    effective_date: normalizeDateInput(props.source?.effective_date ?? null),
    article: props.source?.article ?? '',
    category: props.source?.category ?? '',
    description: props.source?.description ?? '',
    status: props.source?.status ?? 'active',
    stale_reason: props.source?.stale_reason ?? '',
    notes: props.source?.notes ?? '',
    metadata: props.source?.metadata ?? null,
  })

  selectedPriceListId.value = props.source?.price_list_version?.price_list_id ?? null
}

function normalizeDateInput(value: string | null) {
  if (!value) return ''
  return value.slice(0, 10)
}

function normalizeDateTimeInput(value: string | null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const pad = (part: number) => String(part).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

async function loadSuppliers() {
  loadingSuppliers.value = true
  try {
    const response = await suppliersApi.getAll({ is_active: true, per_page: 200 })
    supplierItems.value = response.data
  } finally {
    loadingSuppliers.value = false
  }
}

async function loadPriceLists(supplierId: number | null) {
  priceLists.value = []
  priceListVersions.value = []
  if (!supplierId) {
    selectedPriceListId.value = null
    form.price_list_version_id = null
    return
  }

  loadingPriceLists.value = true
  try {
    const response = await priceListsApi.getAll(supplierId, { type: 'materials', per_page: 200 })
    priceLists.value = response.data
  } finally {
    loadingPriceLists.value = false
  }
}

async function loadVersions(priceListId: number | null) {
  priceListVersions.value = []
  if (!priceListId) {
    form.price_list_version_id = null
    return
  }

  loadingVersions.value = true
  try {
    const response = await priceListsApi.getVersions(priceListId, { per_page: 200 })
    priceListVersions.value = response.data
  } finally {
    loadingVersions.value = false
  }
}

watch(
  () => props.modelValue,
  async (opened) => {
    if (!opened) return

    error.value = null
    resetFieldErrors()
    syncForm()
    await loadSuppliers()

    if (form.supplier_id) {
      await loadPriceLists(form.supplier_id)
    }

    if (selectedPriceListId.value) {
      await loadVersions(selectedPriceListId.value)
    }
  },
)

watch(
  () => form.supplier_id,
  async (next, previous) => {
    if (!props.modelValue || next === previous) return
    selectedPriceListId.value = null
    form.price_list_version_id = null
    await loadPriceLists(next ?? null)
  },
)

watch(
  () => selectedPriceListId.value,
  async (next, previous) => {
    if (!props.modelValue || next === previous) return
    form.price_list_version_id = null
    await loadVersions(next ?? null)
  },
)

async function submit() {
  saving.value = true
  error.value = null
  resetFieldErrors()

  const payload: FinishedProductPriceSourcePayload = {
    supplier_id: form.supplier_id ?? null,
    source_kind: form.source_kind ?? 'manual_entry',
    price_list_version_id: form.price_list_version_id ?? null,
    source_price: form.source_price !== null && form.source_price !== undefined ? Number(form.source_price) : null,
    source_unit: form.source_unit?.trim() || null,
    conversion_factor_to_m2:
      form.conversion_factor_to_m2 !== null && form.conversion_factor_to_m2 !== undefined && form.conversion_factor_to_m2 !== 0
        ? Number(form.conversion_factor_to_m2)
        : null,
    price_per_m2_normalized:
      form.price_per_m2_normalized !== null && form.price_per_m2_normalized !== undefined && form.price_per_m2_normalized !== 0
        ? Number(form.price_per_m2_normalized)
        : null,
    captured_at: form.captured_at || null,
    effective_date: form.effective_date || null,
    article: form.article?.trim() || null,
    category: form.category?.trim() || null,
    description: form.description?.trim() || null,
    status: form.status ?? 'active',
    stale_reason: form.stale_reason?.trim() || null,
    notes: form.notes?.trim() || null,
    metadata: null,
  }

  try {
    const response = isEditMode.value && props.source
      ? await finishedProductPricingApi.updateSource(props.source.id, payload)
      : await finishedProductPricingApi.createSource(props.specificationId, payload)

    emit('saved', response.data)
    emit('update:modelValue', false)
  } catch (e: any) {
    fieldErrors.value = extractFieldErrors(e)
    error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось сохранить источник цены'
  } finally {
    saving.value = false
  }
}
</script>
