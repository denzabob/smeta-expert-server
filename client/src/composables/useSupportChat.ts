import { ref, computed, onUnmounted } from 'vue'
import { supportChatApi, type ChatConversation, type ChatMessage } from '@/api/supportChat'

const POLL_INTERVAL_MS       = 4_000
const TYPING_HEARTBEAT_MS    = 4_000   // repeat heartbeat every 4 s (within the cache TTL of 8 s)
const TYPING_MIN_DELAY_MS    = 3_000   // only start heartbeat after 3 s of continuous typing
const TYPING_STOP_DELAY_MS   = 5_000   // stop heartbeat after 5 s of no keystrokes

// ── Sound ─────────────────────────────────────────────────────────────────────
function playNotificationSound(): void {
  try {
    const ctx = new AudioContext()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.type = 'sine'
    osc.frequency.setValueAtTime(880, ctx.currentTime)
    gain.gain.setValueAtTime(0.12, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + 0.35)
  } catch { /* AudioContext not available in this context */ }
}

export function useSupportChat() {
  // ── State ────────────────────────────────────────────────────────────────────
  const isOpen              = ref(false)
  const conversation        = ref<ChatConversation | null>(null)
  const messages            = ref<ChatMessage[]>([])
  const loadingConversation = ref(false)
  const loadingSend         = ref(false)
  const loadingReopen       = ref(false)
  const error               = ref<string | null>(null)
  const messageInput        = ref('')
  const attachedFile        = ref<File | null>(null)
  const adminTyping         = ref(false)  // true when admin is typing

  // ── Derived ──────────────────────────────────────────────────────────────────
  const unreadCount = computed(() => conversation.value?.unread_count ?? 0)

  const lastMessageId = computed(() => {
    const last = messages.value[messages.value.length - 1]
    return last?.id ?? 0
  })

  // ── Polling internals ────────────────────────────────────────────────────────
  let pollTimer:   ReturnType<typeof setInterval> | null = null
  let bgPollTimer: ReturnType<typeof setInterval> | null = null
  let isPollRunning = false

  // Typing heartbeat internals
  let typingHeartbeatTimer: ReturnType<typeof setInterval> | null = null
  let typingStartTimer:    ReturnType<typeof setTimeout>  | null = null  // 3-sec delay before first send
  let typingStopTimer:     ReturnType<typeof setTimeout>  | null = null
  let isSendingTyping = false

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

  // Background unread poll (slow, runs while widget is closed)
  function startBgPolling(): void {
    if (bgPollTimer !== null) return
    bgPollTimer = setInterval(async () => {
      if (isOpen.value || !conversation.value) return
      try {
        const { conversation: conv } = await supportChatApi.getConversation()
        conversation.value = conv
      } catch { /* silent */ }
    }, 30_000)
  }

  function stopBgPolling(): void {
    if (bgPollTimer !== null) { clearInterval(bgPollTimer); bgPollTimer = null }
  }

  async function poll(): Promise<void> {
    if (isPollRunning || !conversation.value) return
    isPollRunning = true
    const previousLastMessageId = lastMessageId.value
    try {
      const [messagesResult, typingResult] = await Promise.allSettled([
        supportChatApi.getMessages(conversation.value.id),
        supportChatApi.typingStatus(conversation.value.id),
      ])

      if (typingResult.status === 'fulfilled') {
        adminTyping.value = typingResult.value.admin_typing
      }

      if (messagesResult.status === 'fulfilled') {
        if (conversation.value && messagesResult.value.conversation_status) {
          conversation.value.status = messagesResult.value.conversation_status
        }
        const latest = messagesResult.value.messages
        const fresh = latest.filter((message) => message.id > previousLastMessageId)
        messages.value = latest
        if (fresh.length > 0) {
          // Play sound for messages from admin
          if (fresh.some((m) => !m.is_mine)) {
            playNotificationSound()
            // New message arrived from admin — hide typing indicator immediately
            adminTyping.value = false
          }
          // If any incoming message is from someone else and the window is open → mark read
          const hasForeignMessages = fresh.some((m) => !m.is_mine)
          if (hasForeignMessages && isOpen.value) {
            await callMarkRead()
          }
        }
      }
    } catch {
      // Polling errors are intentionally silent; retried on next tick
    } finally {
      isPollRunning = false
    }
  }

  // ── Typing heartbeat ─────────────────────────────────────────────────────────

  /**
   * Call on every keystroke. Sends typing heartbeat only after 3 s of
   * continuous typing, so short messages don't trigger the indicator at all.
   */
  function onUserTyping(): void {
    if (!conversation.value) return

    // Reset inactivity stop timer on each keystroke
    if (typingStopTimer) { clearTimeout(typingStopTimer); typingStopTimer = null }
    typingStopTimer = setTimeout(() => stopTypingHeartbeat(), TYPING_STOP_DELAY_MS)

    // Only start heartbeat after 3 s of continuous typing (ignore fast messages)
    if (!typingStartTimer && !typingHeartbeatTimer) {
      typingStartTimer = setTimeout(() => {
        typingStartTimer = null
        sendTypingHeartbeat()
        typingHeartbeatTimer = setInterval(sendTypingHeartbeat, TYPING_HEARTBEAT_MS)
      }, TYPING_MIN_DELAY_MS)
    }
  }

  async function sendTypingHeartbeat(): Promise<void> {
    if (isSendingTyping || !conversation.value) return
    isSendingTyping = true
    try {
      await supportChatApi.reportTyping(conversation.value.id)
    } catch { /* silent */ } finally {
      isSendingTyping = false
    }
  }

  function stopTypingHeartbeat(): void {
    if (typingStartTimer)   { clearTimeout(typingStartTimer);   typingStartTimer  = null }
    if (typingHeartbeatTimer) { clearInterval(typingHeartbeatTimer); typingHeartbeatTimer = null }
    if (typingStopTimer)    { clearTimeout(typingStopTimer);    typingStopTimer   = null }
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

  // Silently load conversation (for unread badge) without opening the widget
  async function initBackground(): Promise<void> {
    if (conversation.value) return // already loaded
    try {
      const { conversation: conv } = await supportChatApi.getConversation()
      conversation.value = conv
    } catch { /* user may not have a conversation yet */ }
    startBgPolling()
  }

  async function openWidget(): Promise<void> {
    isOpen.value = true
    stopBgPolling()

    if (!conversation.value) {
      await loadConversation()
      // Mark read after initial data arrives
      if (unreadCount.value > 0) {
        await callMarkRead()
      }
    } else {
      // Widget re-opened: silent refresh to catch messages that arrived while closed
      await poll()
      if (conversation.value && conversation.value.unread_count > 0) {
        await callMarkRead()
      }
    }

    startPolling()
  }

  function closeWidget(): void {
    isOpen.value = false
    stopPolling()
    startBgPolling()
  }

  async function sendMessage(): Promise<void> {
    const body = messageInput.value.trim()
    const file = attachedFile.value
    if (!body && !file) return
    if (!conversation.value || loadingSend.value) return
    stopTypingHeartbeat()

    loadingSend.value = true
    error.value = null
    try {
      const { message } = await supportChatApi.sendMessage(conversation.value.id, {
        body: body || undefined,
        file: file ?? undefined,
      })
      messages.value.push(message)
      messageInput.value = ''
      attachedFile.value = null
    } catch {
      error.value = 'Не удалось отправить сообщение. Попробуйте ещё раз.'
    } finally {
      loadingSend.value = false
    }
  }

  async function reopenConversation(): Promise<void> {
    if (!conversation.value || loadingReopen.value) return
    loadingReopen.value = true
    error.value = null
    try {
      const { conversation: reopened } = await supportChatApi.reopen(conversation.value.id)
      conversation.value = reopened
      messages.value = reopened.messages ?? []
    } catch {
      error.value = 'Не удалось открыть диалог. Попробуйте ещё раз.'
    } finally {
      loadingReopen.value = false
    }
  }

  function dismissError(): void {
    error.value = null
  }

  // ── Cleanup ───────────────────────────────────────────────────────────────────
  onUnmounted(() => {
    stopPolling()
    stopBgPolling()
    stopTypingHeartbeat()
  })

  return {
    // state
    isOpen,
    conversation,
    messages,
    loadingConversation,
    loadingSend,
    loadingReopen,
    error,
    messageInput,
    attachedFile,
    adminTyping,
    // derived
    unreadCount,
    // actions
    initBackground,
    openWidget,
    closeWidget,
    sendMessage,
    reopenConversation,
    dismissError,
    onUserTyping,
  }
}
