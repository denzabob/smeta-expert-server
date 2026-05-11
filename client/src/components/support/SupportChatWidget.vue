<template>
  <!-- Launcher FAB -->
  <div v-if="!isOpen" class="support-chat-launcher">
    <div class="launcher-wrapper">
      <v-btn
        class="launcher-btn"
        color="primary"
        icon
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
          <div class="chat-header__meta">
            <p class="chat-header__subtitle">Чат поддержки сервиса</p>
            <StatusChip
              class="chat-header__status"
              :color="conversationStatusColor"
              size="x-small"
              variant="tonal"
            >
              {{ conversationStatusLabel }}
            </StatusChip>
          </div>
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
        <div class="chat-closed-notice__text">Диалог закрыт.</div>
        <v-btn
          color="primary"
          variant="tonal"
          size="small"
          prepend-icon="mdi-chat-plus-outline"
          :loading="loadingReopen"
          @click="reopenConversation"
        >
          Открыть диалог
        </v-btn>
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
          <div class="chat-input-field">
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
          </div>
          <v-btn
            color="primary"
            variant="flat"
            size="small"
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
import { computed, ref, watch, nextTick, onMounted } from 'vue'
import { useSupportChat } from '@/composables/useSupportChat'
import StatusChip from '@/components/layout/StatusChip.vue'
import SupportChatMessageList from '@/components/support/SupportChatMessageList.vue'

const {
  isOpen,
  conversation,
  messages,
  loadingConversation,
  loadingSend,
  loadingReopen,
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
  reopenConversation,
  dismissError,
} = useSupportChat()

onMounted(() => { initBackground() })

const messagesEl        = ref<HTMLElement | null>(null)
const fileInputEl       = ref<HTMLInputElement | null>(null)
const textareaEl        = ref<HTMLTextAreaElement | null>(null)
const attachmentPreview = ref<string>('')
const isAtBottom        = ref(true)

const conversationStatusLabel = computed(() => {
  if (conversation.value?.status === 'closed') return 'Диалог закрыт'
  if (conversation.value?.status === 'pending') return 'Ожидание ответа'
  return 'Диалог открыт'
})

const conversationStatusColor = computed(() => {
  if (conversation.value?.status === 'closed') return 'grey'
  if (conversation.value?.status === 'pending') return 'warning'
  return 'success'
})

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
.support-chat-launcher,
.support-chat-window {
  --chat-offset-x: clamp(16px, 2vw, 24px);
  --chat-offset-y: clamp(16px, 2vw, 24px);
}

.support-chat-launcher {
  position: fixed;
  right: calc(var(--chat-offset-x) + env(safe-area-inset-right, 0px));
  bottom: calc(var(--chat-offset-y) + env(safe-area-inset-bottom, 0px));
  z-index: 1200;
  animation: launcher-idle 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.launcher-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
}

.launcher-btn {
  width: 58px !important;
  height: 58px !important;
  min-width: 58px !important;
  min-height: 58px !important;
  border-radius: 50% !important;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72) !important;
  box-shadow: 0 10px 28px rgba(8, 15, 30, 0.14), 0 2px 8px rgba(8, 15, 30, 0.08) !important;
  transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 0 !important;
  backdrop-filter: blur(14px);
}

.launcher-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--v-theme-primary), 0.28) !important;
  box-shadow: 0 14px 32px rgba(8, 15, 30, 0.16), 0 4px 14px rgba(8, 15, 30, 0.1) !important;
}

.launcher-btn:active {
  transform: translateY(0);
  box-shadow: 0 6px 18px rgba(8, 15, 30, 0.12) !important;
}

@keyframes launcher-idle {
  from { transform: scale(0.8); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.support-chat-container {
  position: fixed;
  inset: 0;
  z-index: 1200;
  pointer-events: none;
}

.chat-scrim {
  display: none;
}

.support-chat-window {
  position: fixed;
  right: calc(var(--chat-offset-x) + env(safe-area-inset-right, 0px));
  bottom: calc(var(--chat-offset-y) + 72px + env(safe-area-inset-bottom, 0px));
  width: min(396px, calc(100vw - (var(--chat-offset-x) * 2)));
  height: min(640px, calc(100dvh - 128px));
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 72px),
    rgb(var(--v-theme-surface));
  border-radius: 24px;
  box-shadow: 0 24px 64px rgba(15, 23, 42, 0.18), 0 8px 24px rgba(15, 23, 42, 0.12);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  animation: window-slide-in 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events: auto;
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
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px 16px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
  position: relative;
  z-index: 10;
}

.chat-header__content {
  flex: 1;
  min-width: 0;
}

.chat-header__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.2;
  color: rgb(var(--v-theme-on-surface));
}

.chat-header__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}

.chat-header__subtitle {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.62);
  white-space: nowrap;
}

.chat-header__close {
  flex-shrink: 0;
  margin: -4px -4px 0 0;
}

.chat-header__status {
  flex-shrink: 0;
}

.chat-error-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  gap: 8px;
  background: rgba(var(--v-theme-error), 0.06);
  border-bottom: 1px solid rgba(var(--v-theme-error), 0.12);
  min-height: 40px;
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
  overflow-wrap: anywhere;
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

.chat-messages-wrapper {
  flex: 1 1 0;
  min-height: 0;
  position: relative;
  display: flex;
  flex-direction: column;
  background: rgba(var(--v-theme-surface-variant), 0.18);
}

.chat-messages {
  flex: 1 1 0;
  min-height: 0;
  overflow-y: auto;
  background: transparent;
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

.chat-closed-notice {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 14px 18px;
  text-align: center;
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), 0.68);
  background: rgba(var(--v-theme-surface-variant), 0.28);
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
}

.chat-closed-notice__text {
  min-width: 0;
}

.chat-footer {
  flex-shrink: 0;
  background: rgb(var(--v-theme-surface));
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 14px 14px;
}

.chat-attachment-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: rgba(var(--v-theme-surface-variant), 0.32);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.52);
  border-radius: 16px;
}

.attachment-icon {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgb(var(--v-theme-primary));
}

.attachment-thumbnail {
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  overflow: hidden;
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.48);
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
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.attachment-size {
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.56);
}

.chat-input-bar {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  padding: 8px;
  background: rgba(var(--v-theme-surface-variant), 0.26);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
  border-radius: 20px;
}

.attach-btn {
  flex-shrink: 0;
  color: rgba(var(--v-theme-on-surface), 0.64);
  margin-bottom: 2px;
  transition: color 0.2s ease, background-color 0.2s ease;
}

.attach-btn:hover:not(:disabled) {
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.08);
}

.attach-btn--active {
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.08);
}

.chat-input-field {
  flex: 1;
  min-width: 0;
  display: flex;
}

.message-input {
  flex: 1;
  border: none;
  border-radius: 14px;
  padding: 10px 4px 10px 0;
  font-family: inherit;
  font-size: 0.9375rem;
  line-height: 1.45;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  resize: none;
  overflow-y: hidden;
  transition: color 0.2s ease;
  min-height: 40px;
  max-height: 112px;
  outline: none;
}

.message-input::placeholder {
  color: rgba(var(--v-theme-on-surface), 0.40);
}

.message-input:focus {
  background: transparent;
}

.message-input:disabled {
  background: transparent;
  cursor: not-allowed;
  opacity: 0.6;
}

.send-btn {
  flex-shrink: 0;
  width: 38px !important;
  height: 38px !important;
  min-width: 38px !important;
  min-height: 38px !important;
  padding: 0 !important;
  border-radius: 12px !important;
  margin-bottom: 1px;
}

.typing-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 18px 10px;
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
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.scroll-bottom-btn {
  position: absolute;
  right: 14px;
  bottom: 14px;
  z-index: 20;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.52);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14) !important;
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

@media (max-width: 959px) {
  .support-chat-launcher {
    --chat-offset-x: 16px;
    --chat-offset-y: 16px;
  }
}

@media (max-width: 599px) {
  .launcher-btn {
    width: 54px !important;
    height: 54px !important;
    min-width: 54px !important;
    min-height: 54px !important;
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
    height: min(88dvh, 760px);
    border-radius: 22px 22px 0 0;
    max-height: min(88dvh, 760px);
    box-shadow: 0 -12px 32px rgba(15, 23, 42, 0.16);
    border: 1px solid rgba(var(--v-theme-outline-variant), 0.48);
    border-bottom: none;
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
    padding: 14px 16px 12px;
  }

  .chat-header__title {
    font-size: 1rem;
  }

  .chat-header__meta {
    gap: 6px;
  }

  .chat-header__subtitle {
    white-space: normal;
  }

  .chat-messages {
    font-size: 0.9375rem;
  }

  .chat-input-bar {
    gap: 6px;
    padding: 6px 8px;
  }

  .message-input {
    min-height: 38px;
  }

  .chat-footer {
    gap: 8px;
    padding: 10px 10px calc(10px + env(safe-area-inset-bottom, 0px));
  }

  .chat-attachment-preview {
    padding: 8px 10px;
    gap: 8px;
  }

  .send-btn {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    min-height: 36px !important;
  }
}

.v-theme--dark .support-chat-window {
  border-color: rgba(var(--v-theme-outline-variant), 0.82);
}

.v-theme--dark .message-input {
  background: transparent;
}

.v-theme--dark .message-input:focus {
  background: transparent;
}
</style>
