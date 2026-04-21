<template>
  <PageContainer>
    <PageHeader
      title="Создать идею"
      subtitle="Оформите предложение в общем MD3-паттерне, чтобы команда быстрее поняла задачу и оценила пользу."
    >
      <template #actions>
        <v-btn
          variant="text"
          prepend-icon="mdi-arrow-left"
          :disabled="loading"
          @click="router.push({ name: 'ideas' })"
        >
          Назад к списку
        </v-btn>
      </template>
    </PageHeader>

    <div class="idea-create-hero">
      <div class="idea-create-hero__title">Что делает идею полезной</div>
      <div class="idea-create-hero__text">
        Сформулируйте проблему, ожидаемый результат и при необходимости приложите скриншоты. Это упростит обсуждение и
        ускорит переход от предложения к планированию.
      </div>
    </div>

    <div class="idea-create-layout">
      <section class="idea-create-main">
        <SectionCard
          class="idea-create-card"
          title="Черновик идеи"
          subtitle="Основные поля, теги и подтверждающие материалы собраны в одном форме-флоу."
        >
          <v-alert
            v-if="error"
            type="error"
            variant="tonal"
            class="idea-create-alert"
            closable
            @click:close="error = ''"
          >
            {{ error }}
          </v-alert>

          <v-form class="idea-create-form" @submit.prevent="submit">
            <div class="idea-create-field-grid">
              <v-text-field
                class="idea-form-field"
                v-model="title"
                label="Заголовок"
                hint="Коротко опишите суть улучшения"
                persistent-hint
                variant="solo-filled"
                density="comfortable"
                :disabled="loading"
                required
              />
            </div>

            <v-textarea
              class="idea-form-field"
              v-model="description"
              label="Описание"
              hint="Что сейчас неудобно, что предлагается изменить и какой эффект это даст"
              persistent-hint
              variant="solo-filled"
              density="comfortable"
              rows="7"
              auto-grow
              :disabled="loading"
              required
            />

            <div class="idea-create-section">
              <div class="idea-create-section__header">
                <div class="idea-create-section__title">Теги</div>
                <div class="idea-create-section__subtitle">Ключевые темы для фильтрации и дальнейшего поиска по идеям.</div>
              </div>
              <TagSelect v-model="tags" :disabled="loading" />
            </div>

            <div class="idea-create-section">
              <div class="idea-create-section__header">
                <div class="idea-create-section__title">Скриншоты и вложения</div>
                <div class="idea-create-section__subtitle">
                  Добавьте визуальный контекст, если проблема видна на экране или в текущем сценарии.
                </div>
              </div>
              <AttachmentUploader v-model="attachments" :disabled="loading" @error="onUploadError" />
            </div>
          </v-form>

          <template #actions>
            <div class="idea-create-actions">
              <v-btn type="submit" color="primary" variant="flat" :loading="loading" @click="submit">
                Создать идею
              </v-btn>
              <v-btn variant="tonal" color="primary" :disabled="loading" @click="router.push({ name: 'ideas' })">
                Отмена
              </v-btn>
            </div>
          </template>
        </SectionCard>
      </section>

      <aside class="idea-create-sidebar">
        <SectionCard
          class="idea-create-side-card"
          title="Подсказка"
          subtitle="Небольшой чек-лист, чтобы идея сразу выглядела понятной для команды."
        >
          <div class="idea-checklist">
            <div class="idea-checklist__item">
              <v-icon icon="mdi-check-circle-outline" size="18" />
              <span>Один ясный заголовок без общего описания “нужно улучшить”.</span>
            </div>
            <div class="idea-checklist__item">
              <v-icon icon="mdi-check-circle-outline" size="18" />
              <span>Короткое описание текущей проблемы и желаемого результата.</span>
            </div>
            <div class="idea-checklist__item">
              <v-icon icon="mdi-check-circle-outline" size="18" />
              <span>Теги для будущей фильтрации и понятного группирования инициатив.</span>
            </div>
            <div class="idea-checklist__item">
              <v-icon icon="mdi-check-circle-outline" size="18" />
              <span>Скриншоты, если идея связана с конкретным экраном или сценарием.</span>
            </div>
          </div>
        </SectionCard>
      </aside>
    </div>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ideasApi } from '@/api/ideas'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
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
.idea-create-hero {
  padding: 18px 20px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary-container), 0.32), rgba(var(--v-theme-surface-container-low), 0.92));
}

.idea-create-hero__title {
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
  color: var(--ds-text-primary);
}

.idea-create-hero__text {
  margin-top: 8px;
  max-width: 820px;
  color: var(--ds-text-secondary);
  line-height: 1.55;
}

.idea-create-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 20px;
  align-items: start;
}

.idea-create-main,
.idea-create-sidebar {
  min-width: 0;
}

.idea-create-card,
.idea-create-side-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.idea-create-alert {
  margin-bottom: 16px;
}

.idea-create-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.idea-create-field-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

.idea-create-section {
  padding: 18px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background: rgba(var(--v-theme-surface-container-lowest), 0.84);
}

.idea-create-section__header {
  margin-bottom: 12px;
}

.idea-create-section__title {
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.idea-create-section__subtitle {
  margin-top: 4px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--ds-text-secondary);
}

.idea-create-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  width: 100%;
  justify-content: flex-end;
}

.idea-checklist {
  display: grid;
  gap: 12px;
}

.idea-checklist__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 10px;
  align-items: start;
  color: var(--ds-text-secondary);
  line-height: 1.5;
}

.idea-checklist__item :deep(.v-icon) {
  margin-top: 2px;
  color: rgb(var(--v-theme-primary));
}

@media (max-width: 1024px) {
  .idea-create-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .idea-create-hero {
    padding: 16px;
  }

  .idea-create-section {
    padding: 16px;
  }

  .idea-create-actions {
    justify-content: stretch;
  }

  .idea-create-actions > * {
    flex: 1 1 100%;
  }
}
</style>
