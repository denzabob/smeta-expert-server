<template>
  <v-dialog v-model="dialog" max-width="560" persistent>
    <v-card>
      <v-card-title class="d-flex align-center pa-4 pb-3">
        <v-icon size="small" class="mr-2" color="primary">mdi-file-document-plus-outline</v-icon>
        <span class="text-subtitle-1 font-weight-medium">{{ title || 'Добавить обоснование' }}</span>
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" :disabled="saving" @click="cancel" />
      </v-card-title>

      <v-divider />

      <v-card-text class="pa-4">
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
          />

          <!-- Note -->
          <v-textarea
            v-model="form.note"
            label="Примечание"
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
import { evidenceRunApi } from '@/api/evidenceRun'

const props = defineProps<{
  modelValue: boolean
  linkableType: 'operation_price' | 'price_list_version'
  linkableId: number
  title?: string
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
  { label: 'Сайт поставщика', value: 'supplier_website' },
  { label: 'Ручной ввод', value: 'manual_input' },
  { label: 'Документ', value: 'document' },
  { label: 'Расчёт', value: 'internal_calc' },
  { label: 'Chrome Extension', value: 'chrome_capture' },
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

async function submit() {
  const validation = await formRef.value?.validate()
  if (!validation?.valid) return

  saving.value = true
  errorMsg.value = null

  const fd = new FormData()
  fd.append('asserted_price', form.value.asserted_price)
  fd.append('source_type', form.value.source_type)
  if (form.value.source_url) fd.append('source_url', form.value.source_url)
  if (form.value.note) fd.append('note', form.value.note)
  for (const file of form.value.files) {
    fd.append('files[]', file)
  }

  try {
    await evidenceRunApi.createAndAttach(props.linkableType, props.linkableId, fd)
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
