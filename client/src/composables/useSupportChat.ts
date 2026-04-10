import { ref, computed, onUnmounted } from 'vue'
import { supportChatApi, type ChatConversation, type ChatMessage } from '@/api/supportChat'

const POLL_INTERVAL_MS = 4_000

export function useSupportChat() {
  // ── State ────────────────────────────────────────────────────────────────────
  const isOpen              = ref(false)
  const conversation        = ref<ChatConversation | null>(null)
  const messages            = ref<ChatMessage[]>([])
  const loadingConversation = ref(false)
  const loadingSend         = ref(false)
  const error               = ref<string | null>(null)
  const messageInput        = ref('')

  // ── Derived ──────────────────────────────────────────────────────────────────
  const unreadCount = computed(() => conversation.value?.unread_count ?? 0)

  const lastMessageId = computed(() =>
    messages.value.length > 0 ? messages.value[messages.value.length - 1].id : 0,
  )

  // ── Polling internals ────────────────────────────────────────────────────────
  let pollTimer: ReturnType<typeof setInterval> | null = null
  let isPollRunning = false

  function startPolling(): void {
    if (pollTimer !== null) return // guard: never create parallel timers
    pollTimer = setInterval(() => { poll() }, POLL_INTERVAL_MS)
  }

  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function poll(): Promise<void> {
    if (isPollRunning || !conversation.value) return
    isPollRunning = true
    try {
      const { messages: fresh } = await supportChatApi.getMessages(
        conversation.value.id,
        lastMessageId.value,
      )
      if (fresh.length > 0) {
        messages.value.push(...fresh)
        // If any incoming message is from someone else and the window is open → mark read
        const hasForeignMessages = fresh.some((m) => !m.is_mine)
        if (hasForeignMessages && isOpen.value) {
          await callMarkRead()
        }
      }
    } catch {
      // Polling errors are intentionally silent; retried on next tick
    } finally {
      isPollRunning = false
    }
  }

  // ── Core actions ─────────────────────────────────────────────────────────────

  async function loadConversation(): Promise<void> {
    loadingConversation.value = true
    error.value = null
    try {
      const { conversation: conv } = await supportChatApi.getConversation()
      conversation.value = conv
      messages.value = conv.messages ?? []
    } catch {
      error.value = 'Не удалось загрузить чат. Попробуйте позже.'
    } finally {
      loadingConversation.value = false
    }
  }

  async function callMarkRead(): Promise<void> {
    if (!conversation.value) return
    try {
      await supportChatApi.markRead(conversation.value.id)
      // Optimistically reset unread badge — the backend is the source of truth
      // but this keeps the UI snappy without an extra GET round-trip.
      if (conversation.value) {
        conversation.value.unread_count = 0
      }
    } catch {
      // Non-critical; badge may be slightly stale until next open — acceptable for MVP
    }
  }

  async function openWidget(): Promise<void> {
    isOpen.value = true

    if (!conversation.value) {
      await loadConversation()
      // Mark read after initial data arrives
      if (conversation.value && conversation.value.unread_count > 0) {
        await callMarkRead()
      }
    } else {
      // Widget re-opened: silent refresh to catch messages that arrived while closed
      await poll()
    }

    startPolling()
  }

  function closeWidget(): void {
    isOpen.value = false
    stopPolling()
  }

  async function sendMessage(): Promise<void> {
    const body = messageInput.value.trim()
    if (!body || !conversation.value || loadingSend.value) return

    loadingSend.value = true
    error.value = null
    try {
      const { message } = await supportChatApi.sendMessage(conversation.value.id, body)
      messages.value.push(message)
      messageInput.value = ''
    } catch {
      error.value = 'Не удалось отправить сообщение. Попробуйте ещё раз.'
    } finally {
      loadingSend.value = false
    }
  }

  function dismissError(): void {
    error.value = null
  }

  // ── Cleanup ───────────────────────────────────────────────────────────────────
  onUnmounted(() => {
    stopPolling()
  })

  return {
    // state
    isOpen,
    conversation,
    messages,
    loadingConversation,
    loadingSend,
    error,
    messageInput,
    // derived
    unreadCount,
    // actions
    openWidget,
    closeWidget,
    sendMessage,
    dismissError,
  }
}
