<template>
  <div class="idea-list">
    <IdeaCard
      v-for="idea in ideas"
      :key="idea.id"
      :idea="idea"
      :loading="loadingIdeaId === idea.id"
      @open="$emit('open', $event)"
      @vote="$emit('vote', $event)"
      @clear-vote="$emit('clear-vote', $event)"
    />

    <v-alert v-if="ideas.length === 0" type="info" variant="tonal" class="idea-list__empty">
      Идеи не найдены.
    </v-alert>
  </div>
</template>

<script setup lang="ts">
import type { IdeaItem } from '@/api/ideas'
import IdeaCard from './IdeaCard.vue'

defineProps<{ ideas: IdeaItem[]; loadingIdeaId?: number | null }>()
defineEmits<{
  (e: 'open', id: number): void
  (e: 'vote', payload: { id: number; type: 'up' | 'down' }): void
  (e: 'clear-vote', id: number): void
}>()
</script>

<style scoped>
.idea-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.idea-list__empty {
  border-radius: var(--ds-radius-16);
}
</style>
