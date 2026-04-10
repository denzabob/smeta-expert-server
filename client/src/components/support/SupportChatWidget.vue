<template>
  <!-- FAB button with unread badge -->
  <div class="support-chat-fab">
    <v-badge
      :content="unreadCount"
      :model-value="unreadCount > 0"
      color="error"
      floating
    >
      <v-btn
        icon
        color="primary"
        size="large"
        elevation="3"
        :aria-label="isOpen ? 'Закрыть чат' : 'Открыть чат поддержки'"
        @click="toggleWidget"
      >
        <v-icon>{{ isOpen ? 'mdi-close' : 'mdi-chat-outline' }}</v-icon>
      </v-btn>
    </v-badge>
  </div>

  <!-- Chat window -->
  <v-card
    v-if="isOpen"
    class="support-chat-window"
    elevation="4"
    rounded="lg"
  >
    <!-- Header -->
    <v-card-title class="d-flex align-center justify-space-between pa-3" style="min-height: 52px;">
      <div class="d-flex align-center gap-2">
        <v-icon size="20" color="primary">mdi-headset</v-icon>
        <span class="text-body-1 font-weight-medium">Поддержка</span>
      </div>
      <v-btn icon variant="text" density="compact" @click="closeWidget" aria-label="Закрыть">
        <v-icon size="18">mdi-close</v-icon>
      </v-btn>
    </v-card-title>

    <v-divider />

    <!-- Error banner -->
    <v-alert
      v-if="error"
      type="error"
      density="compact"
      variant="tonal"
      closable
      class="ma-2"
      @click:close="dismissError"
    >
      {{ error }}
    </v-alert>

    <!-- Messages area -->
    <div
      ref="messagesEl"
      class="support-chat-messages overflow-y-auto"
    >
      <SupportChatMessageList
        :messages="messages"
        :loading="loadingConversation"
      />
    </div>

    <v-divider />

    <!-- Closed conversation notice -->
    <div
      v-if="conversation?.status === 'closed'"
      class="text-center text-caption text-medium-emphasis pa-2"
    >
      Диалог закрыт. Для нового вопроса обратитесь к администратору.
    </div>

    <!-- Input area -->
    <div v-else class="d-flex align-end gap-1 pa-2">
      <v-textarea
        v-model="messageInput"
        placeholder="Введите сообщение…"
        rows="1"
        auto-grow
        max-rows="4"
        density="compact"
        variant="outlined"
        hide-details
        class="flex-grow-1"
        :disabled="loadingSend || loadingConversation"
        @keydown.enter.exact.prevent="sendMessage"
      />
      <v-btn
        icon
        color="primary"
        variant="tonal"
        size="small"
        :disabled="!messageInput.trim() || loadingSend || loadingConversation"
        :loading="loadingSend"
        aria-label="Отправить"
        @click="sendMessage"
      >
        <v-icon size="18">mdi-send</v-icon>
      </v-btn>
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
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
  unreadCount,
  openWidget,
  closeWidget,
  sendMessage,
  dismissError,
} = useSupportChat()

const messagesEl = ref<HTMLElement | null>(null)

function scrollToBottom(behavior: ScrollBehavior = 'smooth'): void {
  nextTick(() => {
    const el = messagesEl.value
    if (el) el.scrollTo({ top: el.scrollHeight, behavior })
  })
}

function toggleWidget(): void {
  if (isOpen.value) {
    closeWidget()
  } else {
    openWidget()
  }
}

// Scroll to bottom when messages list length changes
watch(
  () => messages.value.length,
  (newLen, oldLen) => {
    if (newLen > oldLen) {
      // instant on initial load (many messages), smooth on new arrivals
      scrollToBottom(oldLen === 0 ? 'instant' : 'smooth')
    }
  },
)

// Scroll to bottom when the chat window opens (after DOM renders)
watch(isOpen, (opened) => {
  if (opened) scrollToBottom('instant')
})
</script>

<style scoped>
.support-chat-fab {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 1200;
}

.support-chat-window {
  position: fixed;
  bottom: 88px;   /* above the FAB */
  right: 24px;
  width: 360px;
  max-height: 520px;
  display: flex;
  flex-direction: column;
  z-index: 1200;
}

.support-chat-messages {
  flex: 1 1 auto;
  /* height is driven by max-height on the card */
  min-height: 0;   /* required for flex overflow scroll */
  max-height: 360px;
}
</style>
