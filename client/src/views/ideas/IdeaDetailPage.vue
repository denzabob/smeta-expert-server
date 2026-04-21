<template>
  <PageContainer>
    <PageHeader
      title="Детали идеи"
      subtitle="Обсуждение предложения, голосование и статус внедрения в едином MD3-контуре."
    >
      <template #actions>
        <div class="idea-detail-header-actions">
          <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="router.push({ name: 'ideas' })">
            Назад к списку
          </v-btn>
          <v-btn
            v-if="canDelete"
            color="error"
            variant="tonal"
            prepend-icon="mdi-delete-outline"
            :loading="deleting"
            @click="deleteIdea"
          >
            Удалить идею
          </v-btn>
        </div>
      </template>
    </PageHeader>

    <div v-if="idea" class="idea-detail-layout">
      <section class="idea-detail-main md3-content-stack">
        <SectionCard class="idea-detail-card" :loading="loading">
          <div class="idea-detail-topbar">
            <div class="idea-detail-heading">
              <div class="idea-detail-title-row">
                <h1 class="idea-detail-title">{{ idea.title }}</h1>
                <v-chip color="primary" variant="tonal" class="idea-detail-status">
                  {{ formatIdeaStatus(idea.status) }}
                </v-chip>
              </div>
              <div class="idea-detail-meta">
                <span>{{ idea.author_nickname || 'Пользователь' }}</span>
                <span class="idea-detail-meta__dot" />
                <span>{{ formatDate(idea.created_at) }}</span>
              </div>
            </div>

            <div class="idea-detail-votes">
              <IdeaVotes :score="idea.score" :loading="voteLoading" @vote="onVote" @clear="onClearVote" />
            </div>
          </div>

          <div v-if="isAdmin" class="idea-detail-admin">
            <v-select
              class="idea-form-field idea-detail-admin__select"
              v-model="statusDraft"
              :items="statusOptions"
              label="Статус идеи"
              density="comfortable"
              variant="solo-filled"
              :loading="statusLoading"
              @update:model-value="onStatusChange"
            />
          </div>

          <div class="idea-detail-description">
            {{ idea.description }}
          </div>

          <div v-if="idea.tags?.length" class="idea-detail-tags">
            <v-chip
              v-for="tag in idea.tags"
              :key="tag.id"
              size="small"
              color="primary"
              variant="tonal"
            >
              {{ tag.name }}
            </v-chip>
          </div>

          <div v-if="idea.attachments?.length" class="idea-detail-attachments">
            <div class="idea-detail-section-title">Вложения</div>
            <div class="idea-detail-gallery">
              <div v-for="attachment in idea.attachments" :key="attachment.id" class="idea-detail-gallery__item">
                <v-img :src="attachment.url" :alt="attachment.file_path" height="220" cover class="idea-detail-gallery__image" />
              </div>
            </div>
          </div>
        </SectionCard>

        <SectionCard
          class="idea-comments-card"
          title="Комментарии"
          subtitle="Обсуждение и уточнения по идее в общем паттерне surface + thread."
        >
          <CommentForm :loading="commentLoading" @submit="addComment" />
          <div class="idea-comments-card__list">
            <CommentList :comments="idea.comments || []" />
          </div>
        </SectionCard>
      </section>

      <aside class="idea-detail-sidebar">
        <SectionCard
          class="idea-side-card"
          title="Сводка"
          subtitle="Краткие ориентиры по текущему состоянию идеи."
        >
          <div class="idea-side-list">
            <div class="idea-side-item">
              <span class="idea-side-item__label">Статус</span>
              <span class="idea-side-item__value">{{ formatIdeaStatus(idea.status) }}</span>
            </div>
            <div class="idea-side-item">
              <span class="idea-side-item__label">Автор</span>
              <span class="idea-side-item__value">{{ idea.author_nickname || 'Пользователь' }}</span>
            </div>
            <div class="idea-side-item">
              <span class="idea-side-item__label">Создано</span>
              <span class="idea-side-item__value">{{ formatDate(idea.created_at) }}</span>
            </div>
            <div class="idea-side-item">
              <span class="idea-side-item__label">Счёт</span>
              <span class="idea-side-item__value">{{ idea.score }}</span>
            </div>
            <div class="idea-side-item">
              <span class="idea-side-item__label">Комментариев</span>
              <span class="idea-side-item__value">{{ idea.comments?.length || 0 }}</span>
            </div>
          </div>
        </SectionCard>
      </aside>
    </div>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { IDEA_STATUS_LABELS, formatIdeaStatus, ideasApi, type IdeaItem, type IdeaStatus } from '@/api/ideas'
import { useAuthStore } from '@/stores/auth'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import IdeaVotes from '@/components/ideas/IdeaVotes.vue'
import CommentList from '@/components/ideas/CommentList.vue'
import CommentForm from '@/components/ideas/CommentForm.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const loading = ref(false)
const voteLoading = ref(false)
const commentLoading = ref(false)
const statusLoading = ref(false)
const deleting = ref(false)

const idea = ref<IdeaItem | null>(null)
const statusDraft = ref<IdeaStatus>('NEW')

const statusOptions = [
  { title: IDEA_STATUS_LABELS.NEW, value: 'NEW' },
  { title: IDEA_STATUS_LABELS.PLANNED, value: 'PLANNED' },
  { title: IDEA_STATUS_LABELS.REJECTED, value: 'REJECTED' },
  { title: IDEA_STATUS_LABELS.IMPLEMENTED, value: 'IMPLEMENTED' },
]

const ideaId = computed(() => Number(route.params.id))
const isAdmin = computed(() => Number(authStore.user?.id) === 1)
const canDelete = computed(() => {
  if (!idea.value) return false
  const myId = Number(authStore.user?.id)
  return myId === 1 || myId === Number(idea.value.user_id)
})

async function loadIdea() {
  loading.value = true
  try {
    idea.value = await ideasApi.get(ideaId.value)
    statusDraft.value = idea.value.status
  } catch (error) {
    console.error('Failed to load idea:', error)
  } finally {
    loading.value = false
  }
}

async function onVote(type: 'up' | 'down') {
  if (!idea.value) return

  voteLoading.value = true
  try {
    idea.value = await ideasApi.vote(idea.value.id, type)
    statusDraft.value = idea.value.status
  } catch (error) {
    console.error('Failed to vote:', error)
  } finally {
    voteLoading.value = false
  }
}

async function onClearVote() {
  if (!idea.value) return

  voteLoading.value = true
  try {
    idea.value = await ideasApi.removeVote(idea.value.id)
    statusDraft.value = idea.value.status
  } catch (error) {
    console.error('Failed to clear vote:', error)
  } finally {
    voteLoading.value = false
  }
}

async function addComment(comment: string) {
  if (!idea.value) return

  commentLoading.value = true
  try {
    await ideasApi.addComment(idea.value.id, comment)
    await loadIdea()
  } catch (error) {
    console.error('Failed to add comment:', error)
  } finally {
    commentLoading.value = false
  }
}

async function onStatusChange(value: IdeaStatus) {
  if (!idea.value || !isAdmin.value) return

  statusLoading.value = true
  try {
    idea.value = await ideasApi.updateStatus(idea.value.id, value)
  } catch (error) {
    console.error('Failed to update status:', error)
  } finally {
    statusLoading.value = false
  }
}

async function deleteIdea() {
  if (!idea.value) return

  deleting.value = true
  try {
    await ideasApi.delete(idea.value.id)
    router.push({ name: 'ideas' })
  } catch (error) {
    console.error('Failed to delete idea:', error)
  } finally {
    deleting.value = false
  }
}

function formatDate(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

onMounted(() => {
  loadIdea()
})
</script>

<style scoped>
.idea-detail-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: flex-end;
}

.idea-detail-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 20px;
  align-items: start;
}

.idea-detail-main,
.idea-detail-sidebar {
  min-width: 0;
}

.idea-detail-card,
.idea-comments-card,
.idea-side-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.idea-detail-topbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.idea-detail-heading {
  min-width: 0;
}

.idea-detail-title-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}

.idea-detail-title {
  margin: 0;
  font-size: clamp(28px, 3vw, 34px);
  line-height: 1.15;
  font-weight: 800;
  color: var(--ds-text-primary);
}

.idea-detail-status {
  margin-top: 4px;
}

.idea-detail-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  flex-wrap: wrap;
  color: var(--ds-text-secondary);
}

.idea-detail-meta__dot {
  width: 4px;
  height: 4px;
  border-radius: 999px;
  background: var(--ds-text-tertiary);
}

.idea-detail-votes {
  flex: 0 0 auto;
}

.idea-detail-admin {
  max-width: 280px;
  margin-top: 18px;
}

.idea-detail-description {
  margin-top: 24px;
  padding: 18px 20px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background: rgba(var(--v-theme-surface-container-lowest), 0.84);
  color: var(--ds-text-primary);
  line-height: 1.7;
  white-space: pre-line;
}

.idea-detail-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 18px;
}

.idea-detail-attachments {
  margin-top: 24px;
}

.idea-detail-section-title {
  margin-bottom: 12px;
  font-size: 14px;
  font-weight: 700;
  color: var(--ds-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.idea-detail-gallery {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}

.idea-detail-gallery__item {
  padding: 10px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background: rgba(var(--v-theme-surface-container-lowest), 0.9);
}

.idea-detail-gallery__image {
  border-radius: calc(var(--ds-radius-18) - 6px);
}

.idea-comments-card__list {
  margin-top: 18px;
}

.idea-side-list {
  display: grid;
  gap: 12px;
}

.idea-side-item {
  display: grid;
  gap: 4px;
  padding: 14px 16px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgba(var(--v-theme-surface-container-lowest), 0.84);
}

.idea-side-item__label {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ds-text-tertiary);
}

.idea-side-item__value {
  color: var(--ds-text-primary);
  line-height: 1.5;
}

@media (max-width: 1024px) {
  .idea-detail-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .idea-detail-header-actions {
    width: 100%;
    justify-content: stretch;
  }

  .idea-detail-header-actions > * {
    flex: 1 1 100%;
  }

  .idea-detail-description {
    padding: 16px;
  }

  .idea-detail-gallery {
    grid-template-columns: 1fr;
  }
}
</style>
