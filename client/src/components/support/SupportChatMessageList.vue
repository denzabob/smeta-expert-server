<template>
  <!-- Loading skeleton -->
  <div v-if="loading" class="d-flex flex-column gap-3 pa-4">
    <div v-for="i in 3" :key="i" class="d-flex" :class="i % 2 === 0 ? 'justify-end' : ''">
      <v-skeleton-loader type="text" width="60%" />
    </div>
  </div>

  <!-- Empty state -->
  <div
    v-else-if="messages.length === 0"
    class="d-flex flex-column align-center justify-center h-100 text-medium-emphasis pa-4"
  >
    <v-icon size="48" class="mb-2">mdi-chat-outline</v-icon>
    <span class="text-body-2">Напишите нам — мы ответим в ближайшее время.</span>
  </div>

  <!-- Message list -->
  <div v-else class="d-flex flex-column gap-2 pa-3">
    <div
      v-for="msg in messages"
      :key="msg.id"
      class="d-flex"
      :class="msg.is_mine ? 'justify-end' : 'justify-start'"
    >
      <div
        class="chat-bubble text-body-2"
        :class="msg.is_mine ? 'chat-bubble--mine' : 'chat-bubble--theirs'"
      >
        <!-- Sender label for admin messages -->
        <div
          v-if="!msg.is_mine && msg.sender_display_name"
          class="text-caption font-weight-medium mb-1 text-medium-emphasis"
        >
          {{ msg.sender_display_name }}
        </div>

        <div class="chat-bubble__body">{{ msg.body }}</div>

        <div class="text-right text-caption text-disabled mt-1" style="font-size: 10px;">
          {{ formatTime(msg.created_at) }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ChatMessage } from '@/api/supportChat'

defineProps<{
  messages: ChatMessage[]
  loading:  boolean
}>()

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
.chat-bubble {
  max-width: 78%;
  padding: 8px 12px;
  border-radius: 12px;
  word-break: break-word;
  line-height: 1.45;
}

.chat-bubble--mine {
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  border-bottom-right-radius: 4px;
}

.chat-bubble--theirs {
  background-color: rgb(var(--v-theme-surface-variant));
  color: rgb(var(--v-theme-on-surface-variant));
  border-bottom-left-radius: 4px;
}

.chat-bubble__body {
  white-space: pre-wrap;
}
</style>
