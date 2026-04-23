<template>
  <v-dialog :model-value="modelValue" max-width="860" persistent @update:model-value="$emit('update:modelValue', $event)">
    <v-card class="fp-source-dialog-card">
      <v-card-title class="fp-source-dialog-card__header">
        <div>
          <div class="fp-source-dialog-card__title">{{ isEditMode ? 'Редактирование источника цены' : 'Новый источник цены' }}</div>
          <div class="fp-source-dialog-card__subtitle">
            Добавьте цену поставщика или другой источник, который будет участвовать в расчёте фасада.
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text class="fp-source-dialog-card__content">
        <v-alert v-if="error && !hasFieldErrors" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <div class="fp-source-form">
        <v-row dense class="fp-source-form__row">
          <v-col cols="12" md="5">
            <div class="fp-source-supplier-field">
              <v-autocomplete
                v-model="form.supplier_id"
                :items="supplierItems"
                item-title="name"
                item-value="id"
                label="Поставщик"
                clearable
                :loading="loadingSuppliers"
                :error-messages="fieldErrors.supplier_id"
                hide-details="auto"
              />
              <v-btn
                size="small"
                variant="tonal"
                color="primary"
                class="text-none"
                prepend-icon="mdi-plus"
                @click="openCreateSupplierDialog"
              >
                Добавить
              </v-btn>
              <v-btn
                size="small"
                variant="text"
                class="text-none"
                :disabled="!selectedSupplier"
                @click="openEditSupplierDialog"
              >
                Править
              </v-btn>
            </div>
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

        <v-row dense class="fp-source-form__row">
          <v-col cols="12" md="4">
            <v-text-field
              v-model.number="form.source_price"
              type="number"
              min="0.01"
              step="0.01"
              label="Цена *"
              :error-messages="fieldErrors.source_price"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model="form.source_unit"
              label="Единица"
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
              label="Коэффициент к м²"
              :error-messages="fieldErrors.conversion_factor_to_m2"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model.number="form.price_per_m2_normalized"
              type="number"
              min="0.01"
              step="0.01"
              label="Цена за м²"
              :error-messages="fieldErrors.price_per_m2_normalized"
            />
          </v-col>
        </v-row>

        <v-row dense class="fp-source-form__row">
          <v-col cols="12" md="6">
            <v-text-field
              v-model="form.effective_date"
              type="date"
              label="Дата цены"
              :error-messages="fieldErrors.effective_date"
            />
          </v-col>
        </v-row>

        <v-row dense class="fp-source-form__row">
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
        </div>
      </v-card-text>

      <v-card-actions class="fp-source-dialog-card__actions">
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Отмена</v-btn>
        <v-btn color="primary" :loading="saving" @click="submit">
          {{ isEditMode ? 'Сохранить' : 'Создать' }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <v-dialog v-model="showSupplierDialog" max-width="620" persistent>
      <v-card class="fp-source-dialog-card">
        <v-card-title class="fp-source-dialog-card__header">
          <div>
            <div class="fp-source-dialog-card__title">
              {{ editingSupplier ? 'Редактирование поставщика' : 'Новый поставщик' }}
            </div>
            <div class="fp-source-dialog-card__subtitle">
              Поставщик будет доступен для источников цен фасадов.
            </div>
          </div>
        </v-card-title>
        <v-card-text class="fp-source-dialog-card__content">
          <v-alert v-if="supplierError && !hasSupplierFieldErrors" type="error" variant="tonal" density="compact" class="mb-3">
            {{ supplierError }}
          </v-alert>
          <div class="fp-source-form">
          <v-text-field
            v-model="supplierForm.name"
            label="Название"
            :error-messages="supplierFieldErrors.name"
          />
          <v-row dense class="fp-source-form__row">
            <v-col cols="12" md="6">
              <v-text-field
                v-model="supplierForm.website"
                label="Сайт"
                placeholder="https://..."
                :error-messages="supplierFieldErrors.website"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="supplierForm.contact_person"
                label="Контактное лицо"
                :error-messages="supplierFieldErrors.contact_person"
              />
            </v-col>
          </v-row>
          <v-row dense class="fp-source-form__row">
            <v-col cols="12" md="6">
              <v-text-field
                v-model="supplierForm.contact_email"
                label="Эл. почта"
                :error-messages="supplierFieldErrors.contact_email"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="supplierForm.contact_phone"
                label="Телефон"
                :error-messages="supplierFieldErrors.contact_phone"
              />
            </v-col>
          </v-row>
          <v-textarea
            v-model="supplierForm.notes"
            label="Комментарий"
            rows="2"
            auto-grow
            :error-messages="supplierFieldErrors.notes"
          />
          <v-switch v-model="supplierForm.is_active" color="primary" label="Активен" hide-details />
          </div>
        </v-card-text>
        <v-card-actions class="fp-source-dialog-card__actions">
          <v-spacer />
          <v-btn :disabled="supplierSaving" @click="showSupplierDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="supplierSaving" @click="saveSupplier">
            {{ editingSupplier ? 'Сохранить' : 'Создать' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { Supplier, SupplierCreatePayload } from '@/api/suppliers'
import { suppliersApi } from '@/api/suppliers'
import type { FinishedProductPriceSource, FinishedProductPriceSourcePayload } from '@/api/finishedProductPricing'
import { finishedProductPricingApi } from '@/api/finishedProductPricing'
import {
  finishedProductPriceSourceKindItems,
  finishedProductPriceSourceStatusItems,
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
const saving = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const showSupplierDialog = ref(false)
const supplierSaving = ref(false)
const supplierError = ref<string | null>(null)
const supplierFieldErrors = ref<ValidationErrors>({})
const editingSupplier = ref<Supplier | null>(null)

const supplierItems = ref<Supplier[]>([])

const isEditMode = computed(() => !!props.source?.id)
const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)
const hasSupplierFieldErrors = computed(() => Object.keys(supplierFieldErrors.value).length > 0)

const supplierForm = reactive<SupplierCreatePayload>({
  name: '',
  website: '',
  contact_person: '',
  contact_email: '',
  contact_phone: '',
  notes: '',
  is_active: true,
})

const emptyForm = (): FinishedProductPriceSourcePayload => ({
  supplier_id: null,
  source_kind: 'manual_entry',
  source_price: null,
  source_unit: 'м²',
  conversion_factor_to_m2: null,
  price_per_m2_normalized: null,
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
const selectedSupplier = computed(() => supplierItems.value.find((item) => item.id === form.supplier_id) ?? null)

function resetFieldErrors() {
  fieldErrors.value = {}
}

function extractFieldErrors(error: any): ValidationErrors {
  return error?.response?.data?.errors ?? {}
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
    effective_date: normalizeDateInput(props.source?.effective_date ?? null),
    article: props.source?.article ?? '',
    category: props.source?.category ?? '',
    description: props.source?.description ?? '',
    status: props.source?.status ?? 'active',
    stale_reason: props.source?.stale_reason ?? '',
    notes: props.source?.notes ?? '',
    metadata: props.source?.metadata ?? null,
  })
}

function normalizeDateInput(value: string | null) {
  if (!value) return ''
  return value.slice(0, 10)
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

watch(
  () => props.modelValue,
  async (opened) => {
    if (!opened) {
      showSupplierDialog.value = false
      return
    }

    error.value = null
    resetFieldErrors()
    syncForm()
    await loadSuppliers()
  },
)

function resetSupplierErrors() {
  supplierError.value = null
  supplierFieldErrors.value = {}
}

function resetSupplierForm(supplier?: Supplier | null) {
  editingSupplier.value = supplier ?? null
  Object.assign(supplierForm, {
    name: supplier?.name ?? '',
    website: supplier?.website ?? '',
    contact_person: supplier?.contact_person ?? '',
    contact_email: supplier?.contact_email ?? '',
    contact_phone: supplier?.contact_phone ?? '',
    notes: supplier?.notes ?? '',
    is_active: supplier?.is_active ?? true,
  })
  resetSupplierErrors()
}

function openCreateSupplierDialog() {
  resetSupplierForm(null)
  showSupplierDialog.value = true
}

function openEditSupplierDialog() {
  if (!selectedSupplier.value) return
  resetSupplierForm(selectedSupplier.value)
  showSupplierDialog.value = true
}

async function saveSupplier() {
  supplierSaving.value = true
  resetSupplierErrors()

  const payload: SupplierCreatePayload = {
    name: supplierForm.name.trim(),
    website: supplierForm.website?.trim() || null,
    contact_person: supplierForm.contact_person?.trim() || null,
    contact_email: supplierForm.contact_email?.trim() || null,
    contact_phone: supplierForm.contact_phone?.trim() || null,
    notes: supplierForm.notes?.trim() || null,
    is_active: supplierForm.is_active,
  }

  try {
    const saved = editingSupplier.value
      ? await suppliersApi.update(editingSupplier.value.id, payload)
      : await suppliersApi.create(payload)

    await loadSuppliers()
    form.supplier_id = saved.id
    showSupplierDialog.value = false
  } catch (e: any) {
    supplierFieldErrors.value = extractFieldErrors(e)
    supplierError.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось сохранить поставщика'
  } finally {
    supplierSaving.value = false
  }
}

async function submit() {
  saving.value = true
  error.value = null
  resetFieldErrors()

  const payload: FinishedProductPriceSourcePayload = {
    supplier_id: form.supplier_id ?? null,
    source_kind: form.source_kind ?? 'manual_entry',
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

<style scoped>
.fp-source-dialog-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.76);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.028), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.98);
}

.fp-source-dialog-card__header {
  display: flex;
  align-items: flex-start;
  gap: var(--ds-space-12);
  padding: 18px 20px 14px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.6);
}

.fp-source-dialog-card__title {
  font-size: 1.125rem;
  line-height: 1.3;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.fp-source-dialog-card__subtitle {
  margin-top: 4px;
  color: var(--ds-text-secondary);
}

.fp-source-dialog-card__content {
  padding: 20px !important;
}

.fp-source-form {
  display: grid;
  gap: var(--ds-space-12);
}

.fp-source-form__row {
  margin: 0 !important;
}

.fp-source-supplier-field {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: start;
  gap: var(--ds-space-8);
}

.fp-source-dialog-card__actions {
  padding: 12px 20px 18px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
}

@media (max-width: 760px) {
  .fp-source-supplier-field {
    grid-template-columns: 1fr;
  }
}
</style>
