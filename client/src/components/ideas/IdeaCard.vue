<template>
  <v-card class="idea-card" @click="$emit('open', idea.id)">
    <v-card-text class="idea-card__body">
      <div class="idea-card__layout">
        <IdeaVotes
          :score="idea.score"
          :loading="loading"
          @vote="(type) => $emit('vote', { id: idea.id, type })"
          @clear="$emit('clear-vote', idea.id)"
        />

        <div class="idea-card__content">
          <div class="idea-card__title-row">
            <div class="idea-card__title">{{ idea.title }}</div>
            <v-chip size="small" variant="tonal" class="idea-card__status">
              {{ formatIdeaStatus(idea.status) }}
            </v-chip>
          </div>

          <div class="idea-card__description">
            {{ previewDescription }}
          </div>

          <div class="idea-card__tags" v-if="idea.tags?.length">
            <v-chip
              v-for="tag in idea.tags"
              :key="tag.id"
              size="x-small"
              variant="tonal"
            >
              {{ tag.name }}
            </v-chip>
          </div>

          <div class="idea-card__meta">
            <span>{{ idea.author_nickname || 'Пользователь' }}</span>
            <span>Комментарии: {{ idea.comments_count }}</span>
          </div>
        </div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { formatIdeaStatus, type IdeaItem } from '@/api/ideas'
import IdeaVotes from './IdeaVotes.vue'

const props = defineProps<{ idea: IdeaItem; loading?: boolean }>()

defineEmits<{
  (e: 'open', id: number): void
  (e: 'vote', payload: { id: number; type: 'up' | 'down' }): void
  (e: 'clear-vote', id: number): void
}>()

const previewDescription = computed(() => {
  if (!props.idea.description) return ''
  return props.idea.description.length > 170
    ? `${props.idea.description.slice(0, 170)}...`
    : props.idea.description
})
</script>

<style scoped>
.idea-card {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
  transition:
    transform 0.2s ease,
    border-color 0.2s ease,
    background-color 0.2s ease,
    box-shadow 0.2s ease;
  cursor: pointer;
}

.idea-card:hover {
  transform: translateY(-1px);
  border-color: var(--ds-border-strong);
  background: color-mix(in srgb, var(--md-sys-color-surface-container) 96%, transparent);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

.idea-card__body {
  padding: 18px 20px;
}

.idea-card__layout {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.idea-card__content {
  flex: 1 1 auto;
  min-width: 0;
}

.idea-card__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.idea-card__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--ds-text-primary);
}

.idea-card__status {
  flex-shrink: 0;
}

.idea-card__description {
  margin-top: 8px;
  font-size: 14px;
  line-height: 1.6;
  color: var(--ds-text-secondary);
}

.idea-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.idea-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 14px;
  font-size: 12px;
  color: var(--ds-text-tertiary);
}

@media (max-width: 760px) {
  .idea-card__body {
    padding: 16px;
  }

  .idea-card__layout {
    flex-direction: column;
  }
}
</style>
