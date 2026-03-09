<template>
  <v-card class="idea-card" @click="$emit('open', idea.id)">
    <v-card-text>
      <div class="d-flex justify-space-between ga-3">
        <IdeaVotes
          :score="idea.score"
          :loading="loading"
          @vote="(type) => $emit('vote', { id: idea.id, type })"
          @clear="$emit('clear-vote', idea.id)"
        />

        <div class="flex-grow-1">
          <div class="text-subtitle-1 font-weight-medium">{{ idea.title }}</div>
          <div class="text-body-2 text-medium-emphasis mt-1">
            {{ previewDescription }}
          </div>

          <div class="d-flex flex-wrap ga-2 mt-2" v-if="idea.tags?.length">
            <v-chip
              v-for="tag in idea.tags"
              :key="tag.id"
              size="x-small"
              variant="tonal"
            >
              {{ tag.name }}
            </v-chip>
          </div>

          <div class="d-flex align-center ga-3 mt-3 text-caption text-medium-emphasis">
            <span>{{ idea.author_nickname || 'Пользователь' }}</span>
            <span>Комментарии: {{ idea.comments_count }}</span>
            <span>Статус: {{ formatIdeaStatus(idea.status) }}</span>
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
