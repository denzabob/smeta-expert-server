<template>
  <div v-if="isAdmin" class="d-flex flex-wrap ga-2 justify-end">
    <v-select
      v-model="statusDraft"
      :items="statusOptions"
      density="compact"
      variant="outlined"
      hide-details
      class="admin-idea-status"
      :loading="statusLoading"
      @update:model-value="onStatusChange"
    />

    <v-btn
      size="small"
      variant="tonal"
      color="primary"
      prepend-icon="mdi-comment-text-multiple-outline"
      :loading="commentsLoading"
      @click="openCommentsDialog"
    >
      Комментарии
    </v-btn>

    <v-btn
      size="small"
      variant="tonal"
      color="error"
      prepend-icon="mdi-delete-outline"
      :loading="deletingIdea"
      @click="deleteIdea"
    >
      Удалить
    </v-btn>

    <v-dialog v-model="commentsDialog" max-width="820">
      <v-card>
        <v-card-title class="d-flex align-center">
          Комментарии к идее
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="commentsDialog = false" />
        </v-card-title>

        <v-divider />

        <v-card-text>
          <v-alert
            v-if="errorMessage"
            type="error"
            variant="tonal"
            class="mb-3"
            closable
            @click:close="errorMessage = ''"
          >
            {{ errorMessage }}
          </v-alert>

          <v-form class="mb-4" @submit.prevent="addComment">
            <v-textarea
              v-model="newComment"
              label="Добавить комментарий от администратора"
              variant="outlined"
              rows="3"
              density="compact"
              hide-details
              :disabled="addCommentLoading"
            />
            <div class="d-flex justify-end mt-2">
              <v-btn
                type="submit"
                size="small"
                color="primary"
                :loading="addCommentLoading"
                :disabled="!newComment.trim()"
              >
                Добавить комментарий
              </v-btn>
            </div>
          </v-form>

          <div v-if="commentsLoading" class="py-8 text-center text-medium-emphasis">
            <v-progress-circular indeterminate color="primary" />
          </div>

          <v-list v-else-if="comments.length" lines="two" class="pa-0">
            <v-list-item
              v-for="comment in comments"
              :key="comment.id"
              class="px-0"
            >
              <template #prepend>
                <v-avatar size="30" color="primary" variant="tonal">
                  {{ (comment.author_nickname || 'U').slice(0, 1).toUpperCase() }}
                </v-avatar>
              </template>

              <v-list-item-title class="d-flex align-center ga-2">
                <span>{{ comment.author_nickname || 'Пользователь' }}</span>
                <span class="text-caption text-medium-emphasis">{{ formatDate(comment.created_at) }}</span>
              </v-list-item-title>

              <v-list-item-subtitle>
                {{ comment.text || comment.comment }}
              </v-list-item-subtitle>

              <template #append>
                <v-btn
                  size="small"
                  color="error"
                  variant="text"
                  icon="mdi-delete-outline"
                  :loading="deletingCommentId === comment.id"
                  @click="deleteComment(comment.id)"
                />
              </template>
            </v-list-item>
          </v-list>

          <v-alert v-else type="info" variant="tonal">
            У этой идеи пока нет комментариев.
          </v-alert>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { IDEA_STATUS_LABELS, ideasApi, type IdeaComment, type IdeaItem, type IdeaStatus } from '@/api/ideas'

const props = defineProps<{ idea: IdeaItem }>()

const emit = defineEmits<{
  (e: 'updated', idea: IdeaItem): void
  (e: 'deleted', id: number): void
}>()

const authStore = useAuthStore()

const isAdmin = computed(() => {
  const role = String((authStore.user as any)?.role ?? (authStore.user as any)?.user_role ?? '').toLowerCase()
  return Number(authStore.user?.id) === 1 || role === 'admin' || role === 'superadmin'
})

const statusOptions = [
  { title: IDEA_STATUS_LABELS.NEW, value: 'NEW' },
  { title: IDEA_STATUS_LABELS.PLANNED, value: 'PLANNED' },
  { title: IDEA_STATUS_LABELS.REJECTED, value: 'REJECTED' },
  { title: IDEA_STATUS_LABELS.IMPLEMENTED, value: 'IMPLEMENTED' },
]

const statusDraft = ref<IdeaStatus>(props.idea.status)
const statusLoading = ref(false)
const deletingIdea = ref(false)

const commentsDialog = ref(false)
const commentsLoading = ref(false)
const comments = ref<IdeaComment[]>([])
const deletingCommentId = ref<number | null>(null)
const addCommentLoading = ref(false)
const newComment = ref('')
const errorMessage = ref('')

watch(
  () => props.idea.status,
  (value) => {
    statusDraft.value = value
  },
)

async function onStatusChange(status: IdeaStatus) {
  if (!isAdmin.value) return

  statusLoading.value = true
  errorMessage.value = ''

  try {
    const updated = await ideasApi.updateStatus(props.idea.id, status)
    emit('updated', updated)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось изменить статус идеи.'
  } finally {
    statusLoading.value = false
  }
}

async function deleteIdea() {
  if (!isAdmin.value) return
  if (!window.confirm('Удалить идею? Это действие нельзя отменить.')) return

  deletingIdea.value = true
  errorMessage.value = ''

  try {
    await ideasApi.delete(props.idea.id)
    emit('deleted', props.idea.id)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось удалить идею.'
  } finally {
    deletingIdea.value = false
  }
}

async function openCommentsDialog() {
  commentsDialog.value = true
  newComment.value = ''
  await loadComments()
}

async function loadComments() {
  commentsLoading.value = true
  errorMessage.value = ''

  try {
    const detail = await ideasApi.get(props.idea.id)
    comments.value = detail.comments ?? []
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось загрузить комментарии.'
  } finally {
    commentsLoading.value = false
  }
}

async function deleteComment(commentId: number) {
  if (!isAdmin.value) return
  if (!window.confirm('Удалить комментарий?')) return

  deletingCommentId.value = commentId
  errorMessage.value = ''

  try {
    await ideasApi.deleteComment(props.idea.id, commentId)
    comments.value = comments.value.filter((item) => item.id !== commentId)
    await syncIdea()
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось удалить комментарий.'
  } finally {
    deletingCommentId.value = null
  }
}

async function addComment() {
  if (!isAdmin.value) return

  const comment = newComment.value.trim()
  if (!comment) return

  addCommentLoading.value = true
  errorMessage.value = ''

  try {
    await ideasApi.addComment(props.idea.id, comment)
    newComment.value = ''
    await loadComments()
    await syncIdea()
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось добавить комментарий.'
  } finally {
    addCommentLoading.value = false
  }
}

async function syncIdea() {
  try {
    const detail = await ideasApi.get(props.idea.id)
    emit('updated', detail)
  } catch (error) {
    console.error('Failed to sync idea after comments update:', error)
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
</script>

<style scoped>
.admin-idea-status {
  min-width: 170px;
  max-width: 190px;
}
</style>
