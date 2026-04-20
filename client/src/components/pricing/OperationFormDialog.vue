<template>
  <v-dialog
    :model-value="modelValue"
    max-width="720"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title>
        {{ mode === 'create' ? 'Новая операция' : 'Редактировать операцию' }}
      </v-card-title>

      <v-card-text class="operation-form-dialog__body">
        <v-alert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
        >
          {{ error }}
        </v-alert>

        <div class="operation-form-grid">
          <v-text-field
            v-model="form.name"
            label="Наименование"
            variant="outlined"
            density="compact"
            :error="submitted && !form.name.trim()"
            :error-messages="submitted && !form.name.trim() ? ['Укажите наименование'] : []"
          />

          <v-text-field
            v-model="form.unit"
            label="Единица измерения"
            variant="outlined"
            density="compact"
            :error="submitted && !form.unit.trim()"
            :error-messages="submitted && !form.unit.trim() ? ['Укажите единицу измерения'] : []"
          />

          <v-select
            v-model="form.operation_kind"
            :items="operationKindOptions"
            item-title="label"
            item-value="value"
            label="Вид операции"
            variant="outlined"
            density="compact"
            :error="submitted && !form.operation_kind"
            :error-messages="submitted && !form.operation_kind ? ['Выберите вид операции'] : []"
          />

          <div class="operation-form-dialog__kind-note">
            <div class="operation-form-dialog__kind-note-title">
              {{ kindNoteTitle }}
            </div>
            <div class="operation-form-dialog__kind-note-text">
              {{ kindNoteText }}
            </div>
          </div>

          <v-text-field
            v-model.number="form.min_thickness"
            label="Мин. толщина, мм"
            type="number"
            min="0"
            step="1"
            variant="outlined"
            density="compact"
            clearable
          />

          <v-text-field
            v-model.number="form.max_thickness"
            label="Макс. толщина, мм"
            type="number"
            min="0"
            step="1"
            variant="outlined"
            density="compact"
            clearable
          />

        </div>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="loading" @click="emit('update:modelValue', false)">
          Отмена
        </v-btn>
        <v-btn color="primary" variant="flat" :loading="loading" @click="submit">
          {{ mode === 'create' ? 'Создать' : 'Сохранить' }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'

type OperationKind = 'cutting' | 'edging' | 'drilling' | 'other'

interface OperationFormValue {
  id?: number
  name?: string
  category?: string
  unit?: string
  operation_kind?: OperationKind
  min_thickness?: number | null
  max_thickness?: number | null
  description?: string | null
}

interface OperationFormPayload {
  name: string
  category: string
  unit: string
  operation_kind: OperationKind
  min_thickness: number | null
  max_thickness: number | null
  description: string | null
}

const props = defineProps<{
  modelValue: boolean
  mode: 'create' | 'edit'
  operation?: OperationFormValue | null
  loading?: boolean
  error?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submit: [payload: OperationFormPayload]
}>()

const operationKindOptions: Array<{ label: string; value: OperationKind }> = [
  { label: 'Раскрой', value: 'cutting' },
  { label: 'Кромление', value: 'edging' },
  { label: 'Сверление', value: 'drilling' },
  { label: 'Другое', value: 'other' },
]

const form = reactive<OperationFormPayload>({
  name: '',
  category: '',
  unit: '',
  operation_kind: 'other',
  min_thickness: null,
  max_thickness: null,
  description: '',
})

const submitted = ref(false)

function resetForm() {
  form.name = props.operation?.name ?? ''
  form.category = props.operation?.category ?? ''
  form.unit = props.operation?.unit ?? ''
  form.operation_kind = props.operation?.operation_kind ?? 'other'
  form.min_thickness = props.operation?.min_thickness ?? null
  form.max_thickness = props.operation?.max_thickness ?? null
  form.description = props.operation?.description ?? ''
}

watch(
  () => [props.modelValue, props.operation, props.mode],
  ([opened]) => {
    if (opened) {
      submitted.value = false
      resetForm()
    }
  },
  { immediate: true },
)

const kindNoteTitle = computed(() => {
  if (form.operation_kind === 'cutting') return 'Автовид: раскрой'
  if (form.operation_kind === 'edging') return 'Автовид: кромление'
  if (form.operation_kind === 'drilling') return 'Автовид: сверление'
  return 'Ручной или нейтральный вид'
})

const kindNoteText = computed(() => {
  if (form.operation_kind === 'other') {
    return 'Эта операция остаётся гибкой и не получает автоматическую логику по умолчанию.'
  }

  return 'Этот вид операции сразу готовится для автоматического применения и расчёта.'
})

function deriveCategoryFromKind(kind: OperationKind, fallback?: string | null): string {
  if (kind === 'cutting') return 'cutting'
  if (kind === 'edging') return 'edging'
  if (kind === 'drilling') return 'drilling'
  return fallback?.trim() || 'other'
}

function submit() {
  submitted.value = true

  if (!form.name.trim() || !form.unit.trim() || !form.operation_kind) {
    return
  }

  emit('submit', {
    name: form.name.trim(),
    category: deriveCategoryFromKind(form.operation_kind, form.category),
    unit: form.unit.trim(),
    operation_kind: form.operation_kind,
    min_thickness: form.min_thickness === null || form.min_thickness === undefined
      ? null
      : Number(form.min_thickness),
    max_thickness: form.max_thickness === null || form.max_thickness === undefined
      ? null
      : Number(form.max_thickness),
    description: form.description?.trim() ? form.description.trim() : null,
  })
}
</script>

<style scoped>
.operation-form-dialog__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.operation-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.operation-form-dialog__kind-note {
  grid-column: 1 / -1;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(var(--v-theme-primary), 0.06);
  border: 1px solid rgba(var(--v-theme-primary), 0.14);
}

.operation-form-dialog__kind-note-title {
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.78);
  margin-bottom: 4px;
}

.operation-form-dialog__kind-note-text {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.62);
}

@media (max-width: 700px) {
  .operation-form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
