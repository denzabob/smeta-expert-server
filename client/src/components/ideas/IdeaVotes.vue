<template>
  <div class="d-flex align-center ga-1">
    <v-btn
      icon="mdi-arrow-up"
      variant="text"
      color="success"
      :loading="loading"
      @click.stop="$emit('vote', 'up')"
    />
    <span class="text-subtitle-2 px-1">{{ score }}</span>
    <v-btn
      icon="mdi-arrow-down"
      variant="text"
      color="error"
      :loading="loading"
      @click.stop="$emit('vote', 'down')"
    />
    <v-btn
      icon="mdi-close"
      variant="text"
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
