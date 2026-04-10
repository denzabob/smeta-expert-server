<template>
  <!-- Launcher FAB -->
  <div v-if="!isOpen" class="support-chat-launcher">
    <div class="launcher-wrapper">
      <v-btn
        class="launcher-btn"
        color="primary"
        aria-label="Открыть чат поддержки"
        @click="toggleWidget"
      >
        <v-badge
          :content="unreadCount"
          :model-value="unreadCount > 0"
          color="error"
          overlap
        >
          <v-icon size="22">mdi-chat-outline</v-icon>
        </v-badge>
      </v-btn>
    </div>
  </div>

  <!-- Chat widget window -->
  <div v-if="isOpen" class="support-chat-container">
    <!-- Scrim overlay (mobile only) -->
    <div class="chat-scrim" @click="closeWidget" />

    <div class="support-chat-window">
      <!-- Header -->
      <div class="chat-header">
        <div class="chat-header__content">
          <h3 class="chat-header__title">Поддержка</h3>
          <p class="chat-header__subtitle">Мы помогаем сразу</p>
        </div>
        <v-btn
          icon="mdi-close"
          variant="text"
          density="compact"
          size="small"
          class="chat-header__close"
          aria-label="Закрыть"
          @click="closeWidget"
        />
      </div>

      <!-- Error bar -->
      <transition name="fade-down">
        <div v-if="error" class="chat-error-bar">
          <div class="error-content">
            <v-icon size="16">mdi-alert-circle-outline</v-icon>
            <span class="error-text">{{ error }}</span>
          </div>
          <v-btn
            icon="mdi-close"
            variant="plain"
            size="x-small"
            density="compact"
            @click="dismissError"
          />
        </div>
      </transition>

      <!-- Messages area wrapper: relative container for scroll-btn overlay -->
      <div class="chat-messages-wrapper">
        <div ref="messagesEl" class="chat-messages" @scroll="onMessagesScroll">
          <SupportChatMessageList
            :messages="messages"
            :loading="loadingConversation"
          />

          <!-- Admin typing indicator -->
          <transition name="fade">
            <div v-if="adminTyping" class="typing-indicator">
              <div class="typing-dots">
                <span /><span /><span />
              </div>
              <span class="typing-text">Оператор печатает</span>
            </div>
          </transition>
        </div>

        <!-- Scroll to bottom button -->
        <transition name="fade">
          <v-btn
            v-if="!isAtBottom"
            class="scroll-bottom-btn"
            icon
            size="small"
            color="primary"
            variant="elevated"
            aria-label="Прокрутить вниз"
            @click="scrollToBottom()"
          >
            <v-icon size="18">mdi-chevron-down</v-icon>
          </v-btn>
        </transition>
      </div>

      <!-- Closed state notice -->
      <div v-if="conversation?.status === 'closed'" class="chat-closed-notice">
        Диалог закрыт. Для нового вопроса обратитесь к администратору.
      </div>

      <!-- Composer footer -->
      <div v-else class="chat-footer">
        <input
          ref="fileInputEl"
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          style="display: none;"
          @change="onFileSelected"
        />

        <!-- Attachment preview -->
        <div v-if="attachedFile" class="chat-attachment-preview">
          <div v-if="attachmentPreview" class="attachment-thumbnail">
            <img :src="attachmentPreview" :alt="attachedFile.name" />
          </div>
          <div v-else class="attachment-icon">
            <v-icon size="14">mdi-image</v-icon>
          </div>
          <div class="attachment-info">
            <div class="attachment-name">{{ attachedFile.name }}</div>
            <div class="attachment-size">{{ formatFileSize(attachedFile.size) }}</div>
          </div>
          <v-btn
            icon="mdi-close"
            variant="plain"
            size="x-small"
            density="compact"
            @click="attachedFile = null; attachmentPreview = ''"
          />
        </div>

        <!-- Input bar -->
        <div class="chat-input-bar">
          <v-btn
            icon="mdi-paperclip"
            variant="text"
            density="compact"
            size="small"
            class="attach-btn"
            :class="{ 'attach-btn--active': attachedFile }"
            :disabled="loadingSend || loadingConversation"
            aria-label="Прикрепить файл"
            @click="fileInputEl?.click()"
          />
          <textarea
            ref="textareaEl"
            v-model="messageInput"
            class="message-input"
            placeholder="Ваше сообщение…"
            rows="1"
            :disabled="loadingSend || loadingConversation"
            @keydown.enter.exact.prevent="sendMessage"
            @input="onTextareaInput"
            @paste="onPaste"
          />
          <v-btn
            color="primary"
            variant="elevated"
            size="x-small"
            class="send-btn"
            :disabled="(!messageInput.trim() && !attachedFile) || loadingSend || loadingConversation"
            :loading="loadingSend"
            aria-label="Отправить сообщение"
            @click="sendMessage"
          >
            <v-icon size="16">mdi-send</v-icon>
          </v-btn>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'
import { useSupportChat } from '@/composables/useSupportChat'
import SupportChatMessageList from '@/components/support/SupportChatMessageList.vue'

const {
  isOpen,
  conversation,
  messages,
  loadingConversation,
  loadingSend,
  error,
  messageInput,
  attachedFile,
  unreadCount,
  adminTyping,
  onUserTyping,
  initBackground,
  openWidget,
  closeWidget,
  sendMessage,
  dismissError,
} = useSupportChat()

onMounted(() => { initBackground() })

const messagesEl        = ref<HTMLElement | null>(null)
const fileInputEl       = ref<HTMLInputElement | null>(null)
const textareaEl        = ref<HTMLTextAreaElement | null>(null)
const attachmentPreview = ref<string>('')
const isAtBottom        = ref(true)

function scrollToBottom(behavior: ScrollBehavior = 'smooth'): void {
  nextTick(() => {
    const el = messagesEl.value
    if (el) el.scrollTo({ top: el.scrollHeight, behavior })
  })
}

function onMessagesScroll(): void {
  const el = messagesEl.value
  if (!el) return
  isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80
}

function onTextareaInput(): void {
  autoGrowTextarea()
  onUserTyping()
}

function onFileSelected(e: Event): void {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  if (file) {
    attachedFile.value = file
    generatePreview(file)
  }
  input.value = ''
}

function generatePreview(file: File): void {
  if (!file.type.startsWith('image/')) {
    attachmentPreview.value = ''
    return
  }
  const reader = new FileReader()
  reader.onload = (e) => {
    attachmentPreview.value = (e.target?.result as string) || ''
  }
  reader.readAsDataURL(file)
}

function onPaste(e: ClipboardEvent): void {
  const items = e.clipboardData?.items
  if (!items) return
  for (const item of Array.from(items)) {
    if (item.kind === 'file' && item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) {
        attachedFile.value = file
        generatePreview(file)
        e.preventDefault()
        break
      }
    }
  }
}

function toggleWidget(): void {
  if (isOpen.value) {
    closeWidget()
  } else {
    openWidget()
  }
}

function autoGrowTextarea(): void {
  if (!textareaEl.value) return
  const el = textareaEl.value
  el.style.height = 'auto'
  const newHeight = Math.min(el.scrollHeight, 96) // max 96px (4 rows)
  el.style.height = `${newHeight}px`
}

function formatFileSize(bytes: number): string {
  if (bytes < 1_024)           return `${bytes}B`
  if (bytes < 1_024 * 1_024)  return `${(bytes / 1_024).toFixed(1)}KB`
  return `${(bytes / (1_024 * 1_024)).toFixed(1)}MB`
}

watch(
  () => messages.value.length,
  (newLen, oldLen) => {
    if (newLen > oldLen) {
      // Only auto-scroll if already at bottom; otherwise show the scroll-to-bottom btn
      if (isAtBottom.value || oldLen === 0) {
        scrollToBottom(oldLen === 0 ? 'instant' : 'smooth')
      }
    }
  },
)

// Refocus textarea after message is sent
watch(loadingSend, (loading) => {
  if (!loading && isOpen.value) {
    nextTick(() => textareaEl.value?.focus())
  }
})

watch(isOpen, (opened) => {
  if (opened) {
    scrollToBottom('instant')
    // Prevent body scroll when widget is open on mobile
    if (window.innerWidth < 600) {
      document.body.style.overflow = 'hidden'
    }
  } else {
    document.body.style.overflow = ''
  }
})
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════════
   LAUNCHER / FAB — Modern Intercom-style button
   ═══════════════════════════════════════════════════════════════════════════ */

.support-chat-launcher {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 1200;
  animation: launcher-idle 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.launcher-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
}

.launcher-btn {
  width: 56px !important;
  height: 56px !important;
  min-width: 56px !important;
  min-height: 56px !important;
  border-radius: 50% !important;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.08) !important;
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 0 !important;
}

.launcher-btn:hover {
  transform: scale(1.08);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.16), 0 8px 16px rgba(0, 0, 0, 0.1) !important;
}

.launcher-btn:active {
  transform: scale(0.96);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08) !important;
}

@keyframes launcher-idle {
  from { transform: scale(0.8); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   CHAT WIDGET — Container & Window
   ═══════════════════════════════════════════════════════════════════════════ */

.support-chat-container {
  position: fixed;
  z-index: 1200;
}

.chat-scrim {
  display: none;
}

.support-chat-window {
  position: fixed;
  bottom: 88px;
  right: 24px;
  width: 380px;
  height: 600px;
  background: rgb(var(--v-theme-surface));
  border-radius: 12px;
  box-shadow: 0 5px 40px rgba(0, 0, 0, 0.16);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.05);
  animation: window-slide-in 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes window-slide-in {
  from {
    transform: translateY(24px) scale(0.95);
    opacity: 0;
  }
  to {
    transform: translateY(0) scale(1);
    opacity: 1;
  }
}

/* ─── Header ─────────────────────────────────────────────────────────────── */

.chat-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  position: relative;
  z-index: 10;
}

.chat-header__content {
  flex: 1;
}

.chat-header__title {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
  line-height: 1.2;
  color: rgb(var(--v-theme-on-surface));
}

.chat-header__subtitle {
  margin: 2px 0 0;
  font-size: 0.75rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.56);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.chat-header__close {
  flex-shrink: 0;
  margin-left: 8px;
}

/* ─── Error bar with transition ───────────────────────────────────────────── */

.chat-error-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  gap: 8px;
  background: rgba(var(--v-theme-error), 0.06);
  border-bottom: 1px solid rgba(var(--v-theme-error), 0.12);
  min-height: 32px;
}

.error-content {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 1;
  min-width: 0;
}

.error-text {
  font-size: 0.8125rem;
  color: rgb(var(--v-theme-error));
  flex: 1;
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.fade-down-enter-active,
.fade-down-leave-active {
  transition: all 0.2s ease;
}

.fade-down-enter-from {
  opacity: 0;
  transform: translateY(-8px);
}

.fade-down-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* ─── Messages area ──────────────────────────────────────────────────────── */

.chat-messages-wrapper {
  flex: 1 1 0;
  min-height: 0;
  position: relative;
  display: flex;
  flex-direction: column;
}

.chat-messages {
  flex: 1 1 0;
  min-height: 0;
  overflow-y: auto;
  background: rgb(var(--v-theme-surface));
  display: flex;
  flex-direction: column;
}

.chat-messages::-webkit-scrollbar {
  width: 6px;
}

.chat-messages::-webkit-scrollbar-track {
  background: transparent;
}

.chat-messages::-webkit-scrollbar-thumb {
  background: rgba(var(--v-theme-on-surface), 0.15);
  border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
  background: rgba(var(--v-theme-on-surface), 0.25);
}

/* ─── Closed notice ──────────────────────────────────────────────────────── */

.chat-closed-notice {
  padding: 12px 16px;
  text-align: center;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), 0.56);
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}

/* ─── Footer / Composer ──────────────────────────────────────────────────── */

.chat-footer {
  flex-shrink: 0;
  background: rgb(var(--v-theme-surface));
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  display: flex;
  flex-direction: column;
}

/* Attachment preview */
.chat-attachment-preview {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: rgba(var(--v-theme-primary), 0.04);
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.08);
}

.attachment-icon {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: rgba(var(--v-theme-primary), 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgb(var(--v-theme-primary));
}

.attachment-thumbnail {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 6px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
}

.attachment-thumbnail img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.attachment-info {
  flex: 1;
  min-width: 0;
}

.attachment-name {
  font-size: 0.8125rem;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.attachment-size {
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.56);
}

/* Input bar */
.chat-input-bar {
  display: flex;
  align-items: flex-end;
  gap: 6px;
  padding: 8px;
  background: rgb(var(--v-theme-surface));
}

.attach-btn {
  flex-shrink: 0;
  color: rgba(var(--v-theme-on-surface), 0.56);
  transition: all 0.2s ease;
}

.attach-btn:hover:not(:disabled) {
  color: rgb(var(--v-theme-primary));
}

.attach-btn--active {
  color: rgb(var(--v-theme-primary));
}

.message-input {
  flex: 1;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  padding: 8px 12px;
  font-family: inherit;
  font-size: 0.9375rem;
  line-height: 1.4;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.03);
  resize: none;
  overflow-y: hidden;
  transition: all 0.2s ease;
  min-height: 32px;
  max-height: 96px;
  outline: none;
}

.message-input::placeholder {
  color: rgba(var(--v-theme-on-surface), 0.40);
}

.message-input:focus {
  border-color: rgb(var(--v-theme-primary));
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.08);
}

.message-input:disabled {
  background: rgba(var(--v-theme-on-surface), 0.06);
  cursor: not-allowed;
  opacity: 0.6;
}

.send-btn {
  flex-shrink: 0;
  width: 32px !important;
  height: 32px !important;
  min-width: 32px !important;
  min-height: 32px !important;
  padding: 0 !important;
  border-radius: 6px !important;
}

/* ═══════════════════════════════════════════════════════════════════════════
   TYPING INDICATOR
   ═══════════════════════════════════════════════════════════════════════════ */

.typing-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px 4px;
}

.typing-dots {
  display: flex;
  align-items: center;
  gap: 3px;
}

.typing-dots span {
  display: inline-block;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(var(--v-theme-primary), 0.7);
  animation: typing-bounce 1.4s infinite;
}

.typing-dots span:nth-child(2) { animation-delay: 0.2s; }
.typing-dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing-bounce {
  0%, 80%, 100% { transform: scale(1); opacity: 0.4; }
  40% { transform: scale(1.3); opacity: 1; }
}

.typing-text {
  font-size: 0.78rem;
  color: rgba(var(--v-theme-on-surface), 0.56);
  font-style: italic;
}

/* ═══════════════════════════════════════════════════════════════════════════
   SCROLL TO BOTTOM BUTTON
   ═══════════════════════════════════════════════════════════════════════════ */

.scroll-bottom-btn {
  position: absolute;
  bottom: 10px;
  right: 10px;
  z-index: 20;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18) !important;
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* ═══════════════════════════════════════════════════════════════════════════
   MOBILE — Bottom sheet layout
   ═══════════════════════════════════════════════════════════════════════════ */

@media (max-width: 599px) {
  .support-chat-launcher {
    bottom: 16px;
    right: 16px;
  }

  .chat-scrim {
    display: block;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 1199;
    animation: fade-in 0.2s ease;
  }

  @keyframes fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
  }

  .support-chat-window {
    position: fixed;
    bottom: 0;
    right: 0;
    left: 0;
    top: auto;
    width: 100%;
    height: 85dvh;
    border-radius: 16px 16px 0 0;
    max-height: 85dvh;
    box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.12);
    border: none;
    animation: sheet-slide-up 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }

  @keyframes sheet-slide-up {
    from {
      transform: translateY(100%);
      opacity: 0;
    }
    to {
      transform: translateY(0);
      opacity: 1;
    }
  }

  .chat-header {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  }

  .chat-header__title {
    font-size: 1rem;
  }

  .chat-messages {
    font-size: 0.9375rem;
  }

  .chat-input-bar {
    padding: 6px;
    gap: 4px;
  }

  .message-input {
    min-height: 32px;
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   DARK MODE & THEME ADJUSTMENTS
   ═══════════════════════════════════════════════════════════════════════════ */

.v-theme--dark .support-chat-window {
  border-color: rgba(var(--v-theme-on-surface), 0.08);
}

.v-theme--dark .message-input {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.v-theme--dark .message-input:focus {
  background: rgb(var(--v-theme-surface));
}
</style>
