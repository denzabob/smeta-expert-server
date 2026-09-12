<template>
  <v-dialog v-model="open" max-width="720" scrollable>
    <v-card class="expert-create-dialog">
      <v-card-title class="expert-create-dialog__header">
        <div>
          <div class="text-h6 font-weight-bold">Новое исследование</div>
          <div class="text-body-2 text-medium-emphasis">Создайте рабочее пространство экспертного проекта</div>
        </div>
        <v-btn icon="mdi-close" variant="text" aria-label="Закрыть" :disabled="busy" @click="open = false" />
      </v-card-title>

      <v-divider />

      <v-card-text class="expert-create-dialog__body">
        <v-form ref="formRef" @submit.prevent="createProject">
          <div class="expert-create-dialog__grid">
            <v-text-field
              v-model="draft.title"
              label="Название проекта"
              variant="outlined"
              density="comfortable"
              :rules="[required]"
              class="expert-create-dialog__full"
            />
            <v-select
              v-model="draft.direction"
              :items="expertDirections"
              label="Направление исследования"
              variant="outlined"
              density="comfortable"
            />
            <v-select
              v-model="draft.workType"
              :items="expertWorkTypes"
              label="Вид работы"
              variant="outlined"
              density="comfortable"
            />
            <v-text-field v-model="draft.customer" label="Заказчик" variant="outlined" density="comfortable" />
            <v-text-field v-model="draft.object" label="Объект исследования" variant="outlined" density="comfortable" />
            <v-text-field v-model="draft.address" label="Адрес объекта" variant="outlined" density="comfortable" />
            <v-text-field v-model="draft.researchDate" label="Дата исследования / осмотра" type="date" variant="outlined" density="comfortable" />
            <v-textarea
              v-model="questionsText"
              label="Вопросы исследования"
              placeholder="1. ...\n2. ...\n3. ..."
              variant="outlined"
              rows="5"
              auto-grow
              class="expert-create-dialog__full"
            />
          </div>
        </v-form>
      </v-card-text>

      <v-divider />
      <v-card-actions class="expert-create-dialog__actions">
        <v-btn variant="text" :disabled="busy" @click="open = false">Отмена</v-btn>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" :loading="busy" @click="createProject">Создать проект</v-btn>
      </v-card-actions>
      <v-alert v-if="errorMessage" type="error" variant="tonal" class="mx-6 mb-4">{{ errorMessage }}</v-alert>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { VForm } from 'vuetify/components'
import { expertDirections, expertWorkTypes } from '../options'
import type { ExpertProjectDraft } from '../types'
import { expertApi, mapExpertApiError } from '../api'

const open = defineModel<boolean>({ required: true })
const router = useRouter()
const formRef = ref<InstanceType<typeof VForm> | null>(null)
const busy = ref(false)
const errorMessage = ref('')
const draft = reactive<ExpertProjectDraft>({
  title: '',
  direction: expertDirections[0] ?? '',
  workType: expertWorkTypes[0] ?? '',
  customer: '',
  object: '',
  address: '',
  researchDate: '',
  questions: [],
})
const questionsText = computed({
  get: () => draft.questions.join('\n'),
  set: (value: string) => {
    draft.questions = value.split('\n').map((item) => item.trim()).filter(Boolean)
  },
})
const required = (value: string) => Boolean(value?.trim()) || 'Укажите название проекта'

async function createProject() {
  const result = await formRef.value?.validate()
  if (!result?.valid) return
  busy.value=true; errorMessage.value=''
  try {
    const project=await expertApi.createProject(draft)
    open.value = false
    await router.push({ name: 'expert-project-overview', params: { projectId: project.id } })
  } catch(error) {
    const mapped=mapExpertApiError(error)
    errorMessage.value=Object.values(mapped.validationErrors).flat()[0] ?? mapped.message
  } finally { busy.value=false }
}
</script>

<style scoped>
.expert-create-dialog {
  border-radius: var(--md-sys-shape-corner-extra-large);
}

.expert-create-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 24px 18px;
}

.expert-create-dialog__body {
  padding: 24px;
}

.expert-create-dialog__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2px 16px;
}

.expert-create-dialog__full {
  grid-column: 1 / -1;
}

.expert-create-dialog__actions {
  justify-content: flex-end;
  gap: 8px;
  padding: 16px 24px;
}

@media (max-width: 600px) {
  .expert-create-dialog__grid { grid-template-columns: 1fr; }
  .expert-create-dialog__full { grid-column: auto; }
  .expert-create-dialog__header, .expert-create-dialog__body, .expert-create-dialog__actions { padding-inline: 16px; }
}
</style>
