<template>
  <div class="d-flex flex-column ga-3">
    <v-card v-for="comment in comments" :key="comment.id" class="comment-surface">
      <v-card-text>
        <div class="d-flex align-center ga-2 mb-2">
          <v-avatar size="28" color="primary" variant="tonal">
            {{ (comment.author_nickname || 'U').slice(0, 1).toUpperCase() }}
          </v-avatar>
          <span class="text-subtitle-2">{{ comment.author_nickname || 'Пользователь' }}</span>
          <span class="text-caption text-medium-emphasis">{{ formatDate(comment.created_at) }}</span>
        </div>
        <div class="text-body-2">{{ comment.text || comment.comment }}</div>
      </v-card-text>
    </v-card>

    <v-alert v-if="comments.length === 0" type="info" variant="tonal">
      Комментариев пока нет.
    </v-alert>
  </div>
</template>

<script setup lang="ts">
import type { IdeaComment } from '@/api/ideas'

defineProps<{ comments: IdeaComment[] }>()

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
