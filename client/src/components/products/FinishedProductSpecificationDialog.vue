<template>
  <v-dialog :model-value="modelValue" max-width="920" persistent @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">
        <div>
          <div class="text-h6">{{ isEditMode ? 'Редактирование фасада' : 'Новый фасад' }}</div>
          <div class="text-body-2 text-medium-emphasis">
            Укажите основные характеристики фасада. Цены и доказательства настраиваются отдельно.
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text class="pt-2">
        <v-alert
          v-if="store.error && !hasFieldErrors"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ store.error }}
        </v-alert>

        <v-row dense>
          <v-col cols="12" md="8">
            <v-text-field
              v-model="form.name"
              label="Название *"
              :error-messages="fieldErrors.name"
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-text-field
              v-model="form.article"
              label="Артикул"
              :error-messages="fieldErrors.article"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="3">
            <v-select
              v-model="form.facade_class"
              :items="facadeClassOptions"
              item-title="label"
              item-value="value"
              label="Класс фасада"
              clearable
              :error-messages="fieldErrors.facade_class"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-select
              v-model="form.base_type"
              :items="baseTypeOptions"
              item-title="label"
              item-value="value"
              label="Основа"
              clearable
              :error-messages="fieldErrors.base_type"
            />
          </v-col>
          <v-col cols="12" md="2">
            <v-text-field
              v-model.number="form.thickness_mm"
              type="number"
              min="1"
              label="Толщина, мм"
              :error-messages="fieldErrors.thickness_mm"
            />
          </v-col>
          <v-col cols="12" md="2">
            <v-select
              v-model="form.covering"
              :items="coveringOptions"
              item-title="label"
              item-value="value"
              label="Покрытие"
              clearable
              :error-messages="fieldErrors.covering"
            />
          </v-col>
          <v-col cols="12" md="2">
            <v-switch
              v-model="form.is_active"
              color="primary"
              inset
              label="Активен"
              hide-details
              class="mt-1"
            />
          </v-col>
        </v-row>

        <v-row dense>
          <v-col cols="12" md="3">
            <v-select
              v-model="form.cover_type"
              :items="coverTypeOptions"
              item-title="label"
              item-value="value"
              label="Тип покрытия"
              clearable
              :error-messages="fieldErrors.cover_type"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model="form.collection"
              label="Коллекция"
              :error-messages="fieldErrors.collection"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model="form.decor_label"
              label="Декор"
              :error-messages="fieldErrors.decor_label"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-text-field
              v-model="form.price_group_label"
              label="Ценовая группа"
              :error-messages="fieldErrors.price_group_label"
            />
          </v-col>
        </v-row>

        <v-textarea
          v-model="form.notes"
          label="Примечание"
          rows="3"
          auto-grow
          :error-messages="fieldErrors.notes"
        />

        <v-alert type="info" variant="tonal" density="compact" class="mt-4">
          Здесь редактируются только параметры фасада. Источники цен и подтверждающие материалы открываются из карточки фасада.
        </v-alert>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Отмена</v-btn>
        <v-btn color="primary" :loading="store.saving" @click="submit">
          {{ isEditMode ? 'Сохранить' : 'Создать' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { FinishedProductSpecification, FinishedProductSpecificationFormData } from '@/api/finishedProductSpecifications'
import { useFinishedProductSpecificationsStore } from '@/stores/finishedProductSpecifications'
import {
  baseTypeOptions,
  coverTypeOptions,
  coveringOptions,
  facadeClassOptions,
} from './finishedProductSpecificationOptions'

const props = defineProps<{
  modelValue: boolean
  specification?: FinishedProductSpecification | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'saved', value: FinishedProductSpecification): void
}>()

const store = useFinishedProductSpecificationsStore()

const emptyForm = (): FinishedProductSpecificationFormData => ({
  product_type: 'facade',
  name: '',
  article: '',
  is_active: true,
  facade_class: null,
  base_type: null,
  thickness_mm: null,
  covering: null,
  cover_type: null,
  collection: '',
  decor_label: '',
  price_group_label: '',
  notes: '',
})

const form = reactive<FinishedProductSpecificationFormData>(emptyForm())

const isEditMode = computed(() => !!props.specification?.id)
const fieldErrors = computed(() => store.validationErrors)
const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)

function syncForm() {
  Object.assign(form, emptyForm(), {
    ...props.specification,
    product_type: 'facade',
  })
}

watch(
  () => props.modelValue,
  (opened) => {
    if (opened) {
      store.resetValidationErrors()
      syncForm()
    }
  },
)

watch(
  () => props.specification,
  () => {
    if (props.modelValue) {
      syncForm()
    }
  },
)

async function submit() {
  const payload: FinishedProductSpecificationFormData = {
    product_type: 'facade',
    name: String(form.name ?? '').trim(),
    article: form.article?.trim() || null,
    is_active: !!form.is_active,
    facade_class: form.facade_class || null,
    base_type: form.base_type || null,
    thickness_mm: form.thickness_mm ?? null,
    covering: form.covering || null,
    cover_type: form.cover_type || null,
    collection: form.collection?.trim() || null,
    decor_label: form.decor_label?.trim() || null,
    price_group_label: form.price_group_label?.trim() || null,
    notes: form.notes?.trim() || null,
  }

  try {
    const saved = isEditMode.value && props.specification
      ? await store.updateItem(props.specification.id, payload)
      : await store.createItem(payload)

    emit('saved', saved)
    emit('update:modelValue', false)
  } catch {
    // Ошибка уже положена в store.error / validationErrors
  }
}
</script>
