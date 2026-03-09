<template>
  <v-container>
    <div class="d-flex align-center justify-space-between mb-4">
      <h1 class="text-h5">Создать идею</h1>
      <v-btn variant="text" @click="router.push({ name: 'ideas' })">Назад к списку</v-btn>
    </div>

    <v-card class="ideas-surface">
      <v-card-text>
        <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = ''">
          {{ error }}
        </v-alert>

        <v-form class="idea-create-form" @submit.prevent="submit">
          <v-text-field
            class="idea-form-field mb-3"
            v-model="title"
            label="Заголовок"
            variant="solo-filled"
            density="comfortable"
            :disabled="loading"
            required
          />

          <v-textarea
            class="idea-form-field mb-3"
            v-model="description"
            label="Описание"
            variant="solo-filled"
            density="comfortable"
            rows="6"
            :disabled="loading"
            required
          />

          <div class="mb-3">
            <TagSelect v-model="tags" />
          </div>

          <div class="mt-4">
            <AttachmentUploader v-model="attachments" @error="onUploadError" />
          </div>

          <div class="d-flex ga-2 mt-4">
            <v-btn type="submit" color="primary" :loading="loading">Создать</v-btn>
            <v-btn variant="text" :disabled="loading" @click="router.push({ name: 'ideas' })">Отмена</v-btn>
          </div>
        </v-form>
      </v-card-text>
    </v-card>
  </v-container>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ideasApi } from '@/api/ideas'
import TagSelect from '@/components/ideas/TagSelect.vue'
import AttachmentUploader from '@/components/ideas/AttachmentUploader.vue'

const router = useRouter()

const loading = ref(false)
const error = ref('')

const title = ref('')
const description = ref('')
const tags = ref<string[]>([])
const attachments = ref<File[]>([])

function onUploadError(message: string) {
  error.value = message
}

async function submit() {
  error.value = ''
  if (!title.value.trim() || !description.value.trim()) {
    error.value = 'Заполните заголовок и описание.'
    return
  }

  loading.value = true
  try {
    const created = await ideasApi.create({
      title: title.value.trim(),
      description: description.value.trim(),
      tags: tags.value,
      attachments: attachments.value,
    })

    router.push({ name: 'ideas-detail', params: { id: created.id } })
  } catch (requestError: any) {
    error.value = requestError?.response?.data?.message || 'Не удалось создать идею.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.idea-create-form {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
</style>
