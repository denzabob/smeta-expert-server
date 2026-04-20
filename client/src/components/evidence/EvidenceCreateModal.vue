<template>
  <v-dialog v-model="dialog" max-width="560" persistent>
    <v-card @paste.capture="handlePaste">
      <v-card-title class="d-flex align-center pa-4 pb-3">
        <v-icon size="small" class="mr-2" color="primary">mdi-file-document-plus-outline</v-icon>
        <span class="text-subtitle-1 font-weight-medium modal-title">{{ title || 'Добавить обоснование' }}</span>
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" :disabled="saving" @click="cancel" />
      </v-card-title>

      <v-divider />

      <v-card-text class="pa-4">
        <div v-if="operationName" class="operation-context mb-4">
          <div class="operation-context__label">Операция</div>
          <div class="operation-context__name">{{ operationName }}</div>
        </div>

        <v-form ref="formRef" @submit.prevent="submit">
          <!-- Asserted price -->
          <v-text-field
            v-model="form.asserted_price"
            label="Цена (руб.) *"
            type="number"
            min="0"
            step="0.01"
            :rules="[v => !!v || 'Цена обязательна', v => Number(v) >= 0 || 'Не может быть < 0']"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <!-- Source type -->
          <v-select
            v-model="form.source_type"
            :items="sourceTypeOptions"
            item-title="label"
            item-value="value"
            label="Тип источника *"
            :rules="[v => !!v || 'Выберите тип источника']"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <!-- Source URL -->
          <v-text-field
            v-model="form.source_url"
            label="Ссылка на источник"
            placeholder="https://..."
            :rules="[v => !v || /^https?:\/\/.+/.test(v) || 'Некорректный URL']"
            variant="outlined"
            density="compact"
            class="mb-3"
            @blur="normalizeUrlField"
          />

          <!-- Note -->
          <v-textarea
            v-model="form.note"
            label="Комментарий (необязательно)"
            hint="Отображается в источнике и может использоваться в отчёте"
            persistent-hint
            rows="2"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <!-- Files -->
          <v-file-input
            v-model="form.files"
            label="Файлы (необязательно)"
            multiple
            variant="outlined"
            density="compact"
            prepend-icon="mdi-paperclip"
            chips
            show-size
            accept="image/*,.pdf,.xlsx,.xls,.doc,.docx"
          />

          <!-- Error -->
          <v-alert
            v-if="errorMsg"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-2"
          >
            {{ errorMsg }}
          </v-alert>
        </v-form>
      </v-card-text>

      <v-divider />

      <v-card-actions class="pa-3">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="cancel">Отмена</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          @click="submit"
        >
          Сохранить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import api from '@/api/axios'

const props = defineProps<{
  modelValue: boolean
  targetType: 'operation' | 'material' | 'labor' | 'product'
  targetId: number
  unit?: string
  title?: string
  operationName?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: []
}>()

const dialog = ref(props.modelValue)

watch(() => props.modelValue, (val) => {
  dialog.value = val
  if (val) resetForm()
})

watch(dialog, (val) => {
  emit('update:modelValue', val)
})

const sourceTypeOptions = [
  { label: 'Сайт поставщика', value: 'url' },
  { label: 'Ручной ввод', value: 'manual' },
  { label: 'Документ', value: 'file' },
]

const formRef = ref<InstanceType<typeof import('vuetify/components').VForm> | null>(null)
const saving = ref(false)
const errorMsg = ref<string | null>(null)

const form = ref({
  asserted_price: '',
  source_type: '',
  source_url: '',
  note: '',
  files: [] as File[],
})

function resetForm() {
  form.value = { asserted_price: '', source_type: '', source_url: '', note: '', files: [] }
  errorMsg.value = null
}

function cancel() {
  if (saving.value) return
  dialog.value = false
}

function normalizeSourceUrl(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (/^https?:\/\//i.test(trimmed)) return trimmed
  return `https://${trimmed}`
}

function normalizeUrlField() {
  form.value.source_url = normalizeSourceUrl(form.value.source_url)
}

function handlePaste(event: ClipboardEvent) {
  const clipboardItems = event.clipboardData?.items
  if (!clipboardItems?.length) return

  const pastedFiles: File[] = []

  for (const item of clipboardItems) {
    if (item.kind !== 'file' || !item.type.startsWith('image/')) continue

    const file = item.getAsFile()
    if (!file) continue

    const extension = file.type.split('/')[1] || 'png'
    pastedFiles.push(
      new File(
        [file],
        `pasted-image-${Date.now()}-${pastedFiles.length + 1}.${extension}`,
        { type: file.type },
      ),
    )
  }

  if (!pastedFiles.length) return

  event.preventDefault()
  form.value.files = [...form.value.files, ...pastedFiles]
}

async function submit() {
  normalizeUrlField()

  const validation = await formRef.value?.validate()
  if (!validation?.valid) return

  saving.value = true
  errorMsg.value = null

  const fd = new FormData()
  fd.append('target_type', props.targetType)
  fd.append('target_id', String(props.targetId))
  fd.append('value', form.value.asserted_price)
  fd.append('source_type', form.value.source_type)
  if (form.value.source_url) fd.append('source_url', form.value.source_url)
  if (form.value.note) fd.append('notes', form.value.note)
  if (props.unit) fd.append('unit', props.unit)
  for (const file of form.value.files) {
    fd.append('files[]', file)
  }

  try {
    await api.post('/api/pricing/manual-source', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    dialog.value = false
    emit('created')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    const serverMsg = e.response?.data?.message
    const fieldErrors = e.response?.data?.errors
    if (fieldErrors) {
      errorMsg.value = Object.values(fieldErrors).flat().join('. ')
    } else {
      errorMsg.value = serverMsg || 'Ошибка при сохранении'
    }
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.modal-title {
  min-width: 0;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: normal;
  overflow-wrap: anywhere;
}

.operation-context {
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.05);
  border: 1px solid rgba(var(--v-theme-primary), 0.12);
}

.operation-context__label {
  font-size: 11px;
  line-height: 1.3;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(0, 0, 0, 0.5);
}

.operation-context__name {
  margin-top: 4px;
  font-size: 14px;
  line-height: 1.4;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.84);
}
</style>
