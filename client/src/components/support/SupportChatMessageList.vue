<template>
  <!-- Loading skeleton -->
  <div v-if="loading" class="support-chat-state support-chat-state--loading">
    <AppStateBlock
      loading
      title="Подключаем чат"
      description="Подгружаем историю переписки."
      density="compact"
    />
  </div>

  <!-- Empty state -->
  <div
    v-else-if="messages.length === 0"
    class="support-chat-state"
  >
    <AppStateBlock
      icon="mdi-chat-outline"
      title="Чат готов к сообщению"
      description="Опишите вопрос, и мы ответим в ближайшее рабочее время."
      density="compact"
    />
  </div>

  <!-- Message list -->
  <div v-else class="support-chat-message-list">
    <template v-for="(msg, index) in messages" :key="msg.id">
      <!-- Date separator -->
      <div v-if="showDateSeparator(index)" class="date-separator">
        <span class="date-separator__label">{{ formatDateLabel(msg.created_at) }}</span>
      </div>

      <div
        class="support-chat-message-row"
        :class="msg.is_mine ? 'support-chat-message-row--mine' : 'support-chat-message-row--theirs'"
      >
        <div
          class="chat-bubble"
          :class="[
            msg.is_mine ? 'chat-bubble--mine' : 'chat-bubble--theirs',
            !msg.body && msg.attachments?.length ? 'chat-bubble--image-only' : ''
          ]"
        >
          <!-- Sender label for admin messages -->
          <div
            v-if="!msg.is_mine && msg.sender_display_name"
            class="chat-bubble__sender"
          >
            {{ msg.sender_display_name }}
          </div>

          <div v-if="msg.body" class="chat-bubble__body">{{ msg.body }}</div>

          <!-- Attachments -->
          <ChatAttachmentItem
            v-for="att in (msg.attachments ?? [])"
            :key="att.id"
            :attachment="att"
          />

          <div class="chat-bubble__meta">
            <span class="chat-bubble__time">{{ formatTime(msg.created_at) }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { ChatMessage } from '@/api/supportChat'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import ChatAttachmentItem from './ChatAttachmentItem.vue'

const props = defineProps<{
  messages: ChatMessage[]
  loading:  boolean
}>()

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function isSameDay(a: string, b: string): boolean {
  const da = new Date(a), db = new Date(b)
  return da.getFullYear() === db.getFullYear() &&
    da.getMonth() === db.getMonth() &&
    da.getDate() === db.getDate()
}

function showDateSeparator(index: number): boolean {
  if (index === 0) return true
  const prev = props.messages[index - 1]
  const curr = props.messages[index]
  if (!prev || !curr) return false
  return !isSameDay(prev.created_at, curr.created_at)
}

function formatDateLabel(iso: string): string {
  const d = new Date(iso)
  const today = new Date()
  const yesterday = new Date()
  yesterday.setDate(today.getDate() - 1)
  if (isSameDay(iso, today.toISOString())) return 'Сегодня'
  if (isSameDay(iso, yesterday.toISOString())) return 'Вчера'
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: d.getFullYear() !== today.getFullYear() ? 'numeric' : undefined })
}
</script>

<style scoped>
.support-chat-state {
  display: flex;
  flex: 1 1 auto;
  align-items: center;
  justify-content: center;
  min-height: 100%;
  padding: 20px;
}

.support-chat-message-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 16px 20px;
}

.support-chat-message-row {
  display: flex;
}

.support-chat-message-row--mine {
  justify-content: flex-end;
}

.support-chat-message-row--theirs {
  justify-content: flex-start;
}

.date-separator {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0 2px;
  flex-shrink: 0;
}

.date-separator::before,
.date-separator::after {
  content: '';
  flex: 1;
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.10);
}

.date-separator__label {
  font-size: 0.7rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  padding: 0 4px;
}

.chat-bubble {
  display: grid;
  gap: 6px;
  max-width: min(82%, 720px);
  padding: 12px 14px 10px;
  border-radius: 18px;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.5;
  font-size: 0.9375rem;
  animation: bubble-appear 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.48);
  box-shadow: none;
  position: relative;
}

@keyframes bubble-appear {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.chat-bubble--mine {
  background: rgba(var(--v-theme-primary), 0.92);
  color: rgb(var(--v-theme-on-primary));
  border-bottom-right-radius: 8px;
  border-color: rgba(var(--v-theme-primary), 0.32);
}

.chat-bubble--theirs {
  background: rgba(var(--v-theme-surface), 0.9);
  color: rgb(var(--v-theme-on-surface));
  border-bottom-left-radius: 8px;
}

.chat-bubble--image-only {
  background: transparent !important;
  border: none !important;
  padding: 4px 0 0 !important;
  margin: 0 !important;
  max-width: min(88%, 720px);
}

.chat-bubble--image-only:hover {
  filter: none !important;
  background: transparent !important;
}

.chat-bubble--image-only .chat-bubble__meta {
  justify-content: flex-end;
  padding-right: 2px;
}

.chat-bubble--mine:hover {
  filter: brightness(0.98);
}

.chat-bubble--theirs:hover {
  border-color: rgba(var(--v-theme-primary), 0.16);
}

.chat-bubble--image-only:hover {
  filter: none;
  background: transparent;
  border-color: transparent;
}

.chat-bubble__sender {
  font-size: 0.69rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: rgba(var(--v-theme-on-surface), 0.56);
}

.chat-bubble__body {
  white-space: pre-wrap;
  word-wrap: break-word;
  letter-spacing: -0.1px;
}

.chat-bubble__meta {
  display: flex;
  justify-content: flex-end;
  align-items: center;
}

.chat-bubble__time {
  font-size: 0.72rem;
  opacity: 0.68;
  line-height: 1.2;
}

.chat-bubble--mine .chat-bubble__time {
  color: rgba(var(--v-theme-on-primary), 0.78);
}

.chat-bubble--theirs .chat-bubble__time {
  color: rgba(var(--v-theme-on-surface), 0.52);
}

:deep(.chat-attachment-item) {
  margin-top: 2px;
}

:deep(.chat-attachment-item + .chat-attachment-item) {
  margin-top: 6px;
}

.chat-bubble--image-only :deep(.chat-attachment-item) {
  margin-top: 0;
}

.chat-bubble--image-only :deep(.chat-attachment-item + .chat-attachment-item) {
  margin-top: 6px;
}

@media (max-width: 600px) {
  .support-chat-message-list {
    padding: 12px;
    gap: 9px;
  }

  .chat-bubble {
    max-width: 90%;
    padding: 11px 12px 9px;
    font-size: 0.90rem;
    border-radius: 16px;
  }
}
</style>
