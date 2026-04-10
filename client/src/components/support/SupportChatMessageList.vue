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
  <div v-else class="d-flex flex-column gap-1 pa-2">
    <template v-for="(msg, index) in messages" :key="msg.id">
      <!-- Date separator -->
      <div v-if="showDateSeparator(index)" class="date-separator">
        <span class="date-separator__label">{{ formatDateLabel(msg.created_at) }}</span>
      </div>

      <div
        class="d-flex"
        :class="msg.is_mine ? 'justify-end' : 'justify-start'"
      >
        <div
          class="chat-bubble text-body-2"
          :class="[
            msg.is_mine ? 'chat-bubble--mine' : 'chat-bubble--theirs',
            !msg.body && msg.attachments?.length ? 'chat-bubble--image-only' : ''
          ]"
        >
          <!-- Sender label for admin messages -->
          <div
            v-if="!msg.is_mine && msg.sender_display_name"
            class="text-caption font-weight-medium mb-1 text-medium-emphasis"
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

          <div class="text-right text-caption mt-1" style="font-size: 10px;">
            {{ formatTime(msg.created_at) }}
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { ChatMessage } from '@/api/supportChat'
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
/* ==========================================================================
   CHAT — PREMIUM SUPPORT SAAS
   ========================================================================== */

/* Loading skeleton */
:deep(.d-flex.flex-column.gap-3) {
  padding: 16px;
  gap: 12px !important;
}

/* Empty state */
:deep(.d-flex.flex-column.align-center.justify-center.h-100) {
  padding: 48px 24px;
  gap: 10px;
}

/* Message list container */
:deep(.d-flex.flex-column.gap-1) {
  padding: 16px;
  gap: 12px !important;
}

/* Если сообщения обернуты в дополнительные flex-row блоки */
:deep(.chat-message-row) {
  margin-bottom: 10px;
}

:deep(.chat-message-row:last-child) {
  margin-bottom: 0;
}

/* ==========================================================================
   DATE SEPARATORS
   ========================================================================== */

.date-separator {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12px 0 6px;
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
  color: rgba(var(--v-theme-on-surface), 0.45);
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  padding: 0 4px;
}

/* ==========================================================================
   MESSAGE BUBBLES
   ========================================================================== */

.chat-bubble {
  max-width: min(82%, 720px);
  padding: 10px 14px;
  border-radius: 16px;
  word-break: break-word;
  line-height: 1.5;
  font-size: 0.9375rem;
  animation: bubble-appear 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  border: 1px solid transparent;
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

/* Мои сообщения */
.chat-bubble--mine {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  border-bottom-right-radius: 6px;
  border-color: rgba(var(--v-theme-primary), 0.9);
}

/* Сообщения оператора / системы */
.chat-bubble--theirs {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.10);
  border-bottom-left-radius: 6px;
}

/* Image-only messages — no background or border */
.chat-bubble--image-only {
  background: transparent !important;
  border: none !important;
  padding: 4px 0 !important;
  margin: 0 !important;
}

.chat-bubble--image-only:hover {
  filter: none !important;
  background: transparent !important;
}

/* Time should be visible in image-only messages */
.chat-bubble--image-only :deep(.text-caption.mt-1) {
  color: rgba(0, 0, 0, 0.85) !important;
  background: rgba(255, 255, 255, 0.9);
  padding: 4px 8px;
  border-radius: 4px;
  backdrop-filter: blur(4px);
  font-weight: 600;
}

/* Hover */
.chat-bubble--mine:hover {
  filter: brightness(0.98);
}

.chat-bubble--theirs:hover {
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-color: rgba(var(--v-theme-on-surface), 0.14);
}

.chat-bubble--image-only:hover {
  filter: none;
  background: transparent;
  border-color: transparent;
}

/* Текст сообщения */
.chat-bubble__body {
  white-space: pre-wrap;
  word-wrap: break-word;
  margin-bottom: 6px;
  letter-spacing: -0.1px;
}

/* Имя отправителя */
:deep(.text-caption.font-weight-medium:not(.text-disabled)) {
  font-size: 0.69rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 4px;
  opacity: 0.72;
}

/* Время */
:deep(.text-caption.mt-1) {
  font-size: 0.72rem;
  margin-top: 4px !important;
  opacity: 0.68;
  line-height: 1.2;
}

.chat-bubble--mine :deep(.text-caption.mt-1) {
  color: rgba(var(--v-theme-on-primary), 0.78);
}

.chat-bubble--theirs :deep(.text-caption.mt-1) {
  color: rgba(var(--v-theme-on-surface), 0.52);
}

/* ==========================================================================
   ATTACHMENTS
   ========================================================================== */

:deep(.chat-attachment-item) {
  margin-top: 8px;
}

:deep(.chat-attachment-item + .chat-attachment-item) {
  margin-top: 6px;
}

/* Attachments in image-only messages have proper spacing */
.chat-bubble--image-only :deep(.chat-attachment-item) {
  margin-top: 0;
}

.chat-bubble--image-only :deep(.chat-attachment-item + .chat-attachment-item) {
  margin-top: 6px;
}

/* ==========================================================================
   GROUP SPACING
   ========================================================================== */

/* Если несколько сообщений подряд от одного автора */
:deep(.chat-message-group) {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* Разделение между группами сообщений */
:deep(.chat-message-group + .chat-message-group) {
  margin-top: 12px;
}

/* ==========================================================================
   MOBILE
   ========================================================================== */

@media (max-width: 600px) {
  :deep(.d-flex.flex-column.gap-1) {
    padding: 12px;
    gap: 10px !important;
  }

  .chat-bubble {
    max-width: 90%;
    padding: 9px 12px;
    font-size: 0.90rem;
    border-radius: 14px;
  }
}
</style>
