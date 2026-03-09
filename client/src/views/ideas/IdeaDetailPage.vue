<template>
  <v-container>
    <div class="d-flex align-center justify-space-between mb-4">
      <v-btn variant="text" @click="router.push({ name: 'ideas' })">Назад к списку</v-btn>
      <v-btn
        v-if="canDelete"
        color="error"
        variant="tonal"
        :loading="deleting"
        @click="deleteIdea"
      >
        Удалить идею
      </v-btn>
    </div>

    <v-card class="ideas-surface" :loading="loading">
      <v-card-text v-if="idea">
        <div class="d-flex justify-space-between ga-4 flex-wrap">
          <div>
            <h1 class="text-h5 mb-1">{{ idea.title }}</h1>
            <div class="text-body-2 text-medium-emphasis">
              {{ idea.author_nickname || 'Пользователь' }} | {{ formatDate(idea.created_at) }}
            </div>
          </div>

          <div class="d-flex align-center ga-2">
            <IdeaVotes :score="idea.score" :loading="voteLoading" @vote="onVote" @clear="onClearVote" />
            <v-chip variant="tonal">{{ formatIdeaStatus(idea.status) }}</v-chip>
          </div>
        </div>

        <div v-if="isAdmin" class="mt-3" style="max-width: 260px">
          <v-select
            class="idea-form-field"
            v-model="statusDraft"
            :items="statusOptions"
            label="Статус идеи"
            density="comfortable"
            variant="solo-filled"
            :loading="statusLoading"
            @update:model-value="onStatusChange"
          />
        </div>

        <div class="text-body-1 mt-4" style="white-space: pre-line">{{ idea.description }}</div>

        <div v-if="idea.tags?.length" class="d-flex flex-wrap ga-2 mt-4">
          <v-chip v-for="tag in idea.tags" :key="tag.id" size="small" variant="tonal">{{ tag.name }}</v-chip>
        </div>

        <div v-if="idea.attachments?.length" class="mt-4">
          <div class="text-subtitle-2 mb-2">Вложения</div>
          <v-row>
            <v-col cols="12" sm="6" md="4" v-for="attachment in idea.attachments" :key="attachment.id">
              <v-img :src="attachment.url" :alt="attachment.file_path" height="180" cover class="rounded" />
            </v-col>
          </v-row>
        </div>
      </v-card-text>
    </v-card>

    <v-card class="mt-4 ideas-surface">
      <v-card-title>Комментарии</v-card-title>
      <v-card-text>
        <CommentForm :loading="commentLoading" @submit="addComment" />
        <div class="mt-4">
          <CommentList :comments="idea?.comments || []" />
        </div>
      </v-card-text>
    </v-card>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { IDEA_STATUS_LABELS, formatIdeaStatus, ideasApi, type IdeaItem, type IdeaStatus } from '@/api/ideas'
import { useAuthStore } from '@/stores/auth'
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
