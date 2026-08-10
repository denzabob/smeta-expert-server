<template>
  <SectionCard variant="outlined">
    <div class="d-flex align-center mb-4">
      <div>
        <div class="text-title-medium font-weight-medium">Загрузка XLSX</div>
        <div class="text-body-2 text-medium-emphasis">Файл попадёт на административную проверку.</div>
      </div>
      <v-spacer />
      <v-chip v-if="selectedFile" size="small" variant="tonal">{{ formatBytes(selectedFile.size) }}</v-chip>
    </div>
    <v-form @submit.prevent="submit">
      <v-row dense>
        <v-col cols="12" md="4">
          <v-select v-model="sourcePublicId" :items="sources" item-title="name" item-value="public_id"
            label="Источник (необязательно)" clearable density="compact" variant="outlined" hide-details />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model.number="year" label="Год" type="number" min="1900" max="9999"
            density="compact" variant="outlined" hide-details />
        </v-col>
        <v-col cols="6" md="2">
          <v-select v-model="month" :items="months" label="Месяц" density="compact" variant="outlined"
            clearable hide-details />
        </v-col>
        <v-col cols="12" md="4">
          <v-file-input v-model="fileModel" accept=".xlsx" label="Файл XLSX" prepend-icon="mdi-file-excel-outline"
            density="compact" variant="outlined" hide-details show-size />
        </v-col>
        <v-col cols="12" md="6">
          <v-text-field v-model="sourceUrl" label="URL источника" density="compact" variant="outlined"
            hide-details clearable />
        </v-col>
        <v-col cols="12" md="6">
          <v-text-field v-model="comment" label="Комментарий" density="compact" variant="outlined"
            hide-details clearable maxlength="2000" />
        </v-col>
      </v-row>
      <v-alert v-if="validationError" type="warning" variant="tonal" density="compact" class="mt-3">
        {{ validationError }}
      </v-alert>
      <v-progress-linear v-if="loading" :model-value="progress || undefined" indeterminate-color="primary"
        color="primary" class="mt-4" rounded />
      <div class="d-flex justify-end mt-4">
        <v-btn color="primary" prepend-icon="mdi-upload" type="submit" :loading="loading" :disabled="loading || !datasetPublicId">
          Загрузить
        </v-btn>
      </div>
    </v-form>
  </SectionCard>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { adminPriceIndicesApi } from '../api/adminPriceIndicesApi'
import { getPriceIndicesErrorMessage } from '../errors'
import { formatBytes } from '../status'
import type { StatisticalSource, StatisticalSourceFile } from '../types'

const props = defineProps<{ datasetPublicId: string; sources: StatisticalSource[] }>()
const emit = defineEmits<{
  uploaded: [file: StatisticalSourceFile]
  error: [message: string]
}>()

const sourcePublicId = ref<string | null>(null)
const year = ref<number | null>(new Date().getFullYear())
const month = ref<number | null>(new Date().getMonth() + 1)
const sourceUrl = ref('')
const comment = ref('')
const fileModel = ref<File | File[] | null>(null)
const loading = ref(false)
const progress = ref(0)
const validationError = ref('')
const months = Array.from({ length: 12 }, (_, index) => ({ title: index + 1, value: index + 1 }))
const selectedFile = computed(() => Array.isArray(fileModel.value) ? fileModel.value[0] : fileModel.value)

async function submit() {
  validationError.value = ''
  const file = selectedFile.value
  if (!file || !file.name.toLowerCase().endsWith('.xlsx')) {
    validationError.value = 'Выберите файл формата .xlsx.'
    return
  }
  if ((year.value == null) !== (month.value == null)) {
    validationError.value = 'Год и месяц должны быть указаны вместе.'
    return
  }
  loading.value = true
  progress.value = 0
  try {
    const response = await adminPriceIndicesApi.uploadSourceFile({
      datasetPublicId: props.datasetPublicId,
      sourcePublicId: sourcePublicId.value,
      reportingYear: year.value,
      reportingMonth: month.value,
      sourceUrl: sourceUrl.value || null,
      comment: comment.value || null,
      file,
    }, (value) => { progress.value = value })
    fileModel.value = null
    comment.value = ''
    emit('uploaded', response.data)
  } catch (error) {
    emit('error', getPriceIndicesErrorMessage(error, 'Не удалось загрузить XLSX.'))
  } finally {
    loading.value = false
  }
}
</script>
