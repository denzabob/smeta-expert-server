<template>
  <div class="idea-comment-list">
    <v-card v-for="comment in comments" :key="comment.id" class="idea-comment-card">
      <v-card-text class="idea-comment-card__content">
        <div class="idea-comment-card__header">
          <v-avatar size="32" color="primary" variant="tonal">
            {{ (comment.author_nickname || 'U').slice(0, 1).toUpperCase() }}
          </v-avatar>
          <div class="idea-comment-card__meta">
            <span class="idea-comment-card__author">{{ comment.author_nickname || 'Пользователь' }}</span>
            <span class="idea-comment-card__date">{{ formatDate(comment.created_at) }}</span>
          </div>
        </div>
        <div class="idea-comment-card__text">{{ comment.text || comment.comment }}</div>
      </v-card-text>
    </v-card>

    <v-alert v-if="comments.length === 0" type="info" variant="tonal" class="idea-comment-list__empty">
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

<style scoped>
.idea-comment-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.idea-comment-card {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background: rgba(var(--v-theme-surface-container-lowest), 0.86);
  box-shadow: none;
}

.idea-comment-card__content {
  padding: 18px 20px;
}

.idea-comment-card__header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.idea-comment-card__meta {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.idea-comment-card__author {
  font-size: 14px;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.idea-comment-card__date {
  font-size: 12px;
  color: var(--ds-text-tertiary);
}

.idea-comment-card__text {
  color: var(--ds-text-secondary);
  line-height: 1.6;
  white-space: pre-line;
}
</style>
