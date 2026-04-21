<template>
  <div class="idea-votes">
    <v-btn
      icon="mdi-arrow-up"
      variant="text"
      color="success"
      class="idea-votes__btn"
      :loading="loading"
      @click.stop="$emit('vote', 'up')"
    />
    <span class="idea-votes__score">{{ score }}</span>
    <v-btn
      icon="mdi-arrow-down"
      variant="text"
      color="error"
      class="idea-votes__btn"
      :loading="loading"
      @click.stop="$emit('vote', 'down')"
    />
    <v-btn
      icon="mdi-close"
      variant="text"
      class="idea-votes__btn"
      :loading="loading"
      @click.stop="$emit('clear')"
    />
  </div>
</template>

<script setup lang="ts">
defineProps<{ score: number; loading?: boolean }>()
defineEmits<{
  (e: 'vote', value: 'up' | 'down'): void
  (e: 'clear'): void
}>()
</script>

<style scoped>
.idea-votes {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 40px;
  padding: 4px 6px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-full);
  background: rgba(var(--v-theme-surface-container-lowest), 0.88);
}

.idea-votes__btn {
  flex-shrink: 0;
}

.idea-votes__score {
  min-width: 32px;
  text-align: center;
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-text-primary);
}
</style>
