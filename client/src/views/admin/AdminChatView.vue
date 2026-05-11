<template>
  <div class="admin-chat-view">
    <!-- ── Left: conversation list ── -->
    <div class="chat-list-panel">
      <div class="chat-panel-header">
        <div>
          <div class="chat-panel-title">Диалоги поддержки</div>
          <div class="chat-panel-subtitle">
            {{ pagination.total }} {{ pagination.total === 1 ? 'обращение' : 'обращений' }}
            <span v-if="totalUnreadCount > 0"> · новых: {{ totalUnreadCount }}</span>
          </div>
        </div>
        <v-badge
          v-if="totalUnreadCount > 0"
          :content="totalUnreadCount"
          color="error"
          inline
        />
      </div>

      <!-- Filters -->
      <div class="chat-list-filters">
        <v-text-field
          v-model="search"
          placeholder="Поиск по пользователю..."
          prepend-inner-icon="mdi-magnify"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          @update:model-value="debouncedSearch"
        />
        <v-chip-group v-model="statusFilter" mandatory color="primary" class="chat-status-filter">
          <v-chip value="" size="small" filter>Все</v-chip>
          <v-chip value="open" size="small" filter>Открытые</v-chip>
          <v-chip value="pending" size="small" filter>Ожидание</v-chip>
          <v-chip value="closed" size="small" filter>Закрытые</v-chip>
        </v-chip-group>
        <v-switch
          v-model="unassignedOnly"
          label="Только без ответственного"
          density="compact"
          hide-details
          color="warning"
          class="mt-2"
          @update:model-value="loadList"
        />
      </div>

      <v-divider />

      <!-- Conversation list -->
      <div v-if="listLoading && conversations.length === 0" class="pa-4">
        <v-skeleton-loader v-for="i in 5" :key="i" type="list-item-two-line" class="mb-1" />
      </div>

      <AppStateBlock
        v-else-if="!listLoading && conversations.length === 0"
        icon="mdi-chat-remove-outline"
        title="Диалоги не найдены"
        description="Попробуйте изменить фильтр или поисковый запрос."
        density="compact"
      />

      <v-list v-else density="compact" class="overflow-y-auto chat-list-scroll">
        <v-list-item
          v-for="conv in conversations"
          :key="conv.id"
          :active="activeId === conv.id"
          active-color="primary"
          :class="{ 'font-weight-medium': conv.unread_count > 0 }"
          class="conv-item"
          @click="selectConversation(conv.id)"
        >
          <template #prepend>
            <v-avatar size="34" :color="statusColor(conv.status)" class="text-white text-caption">
              {{ conv.creator?.name?.charAt(0)?.toUpperCase() ?? '?' }}
            </v-avatar>
          </template>

          <v-list-item-title class="text-body-2 font-weight-medium" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            {{ conv.creator?.name ?? 'Неизвестный пользователь' }}
          </v-list-item-title>
          <v-list-item-subtitle class="text-caption">
            <span>{{ conv.subject ?? statusLabel(conv.status) }}</span>
            <v-chip
              v-if="conv.unread_count > 0"
              size="x-small"
              color="error"
              variant="tonal"
              class="ml-1"
            >
              Новые: {{ conv.unread_count }}
            </v-chip>
          </v-list-item-subtitle>

          <template #append>
            <div class="d-flex flex-column align-end gap-1">
              <v-badge
                v-if="conv.unread_count > 0"
                :content="conv.unread_count"
                color="error"
                inline
              />
              <span class="text-caption text-disabled" style="white-space: nowrap;">
                {{ relativeTime(conv.last_message_at) }}
              </span>
            </div>
          </template>
        </v-list-item>
      </v-list>

      <!-- Pagination -->
      <v-divider v-if="pagination.last_page > 1" />
      <div v-if="pagination.last_page > 1" class="d-flex justify-center pa-2">
        <v-pagination
          v-model="currentPage"
          :length="pagination.last_page"
          density="compact"
          size="small"
          @update:model-value="loadList"
        />
      </div>
    </div>

    <!-- ── Divider ── -->
    <v-divider vertical class="chat-shell-divider" />

    <!-- ── Right: conversation thread ── -->
    <div class="chat-thread-panel">
      <!-- No selection -->
      <div
        v-if="!activeId"
        class="chat-thread-empty"
      >
        <AppStateBlock
          icon="mdi-chat-outline"
          title="Выберите диалог"
          description="Сообщения и действия по обращению появятся в этой области."
        />
      </div>

      <!-- Selected conversation -->
      <template v-else>
        <!-- Thread header -->
        <div class="thread-header">
          <div class="flex-grow-1">
            <div class="thread-header__title">
              {{ activeConversation?.creator?.name ?? '...' }}
            </div>
            <div class="thread-header__meta">
              <span>{{ activeConversation?.creator?.email }}</span>
              <StatusChip
                :color="statusColor(activeConversation?.status ?? 'open')"
                size="x-small"
                variant="tonal"
              >
                {{ statusLabel(activeConversation?.status ?? 'open') }}
              </StatusChip>
            </div>
          </div>

          <!-- Header actions -->
          <ButtonGroup class="thread-header__actions">
            <v-text-field
              v-model="adminAlias"
              placeholder="Псевдоним"
              density="compact"
              variant="outlined"
              hide-details
              clearable
              class="thread-header__alias"
              :loading="aliasLoading"
              :disabled="aliasSaving"
              @keydown.enter.prevent="saveAdminAlias"
            />
            <v-btn
              icon
              variant="text"
              density="compact"
              :loading="aliasSaving"
              title="Сохранить псевдоним"
              @click="saveAdminAlias"
            >
              <v-icon size="18">mdi-content-save-outline</v-icon>
            </v-btn>
            <v-btn
              v-if="!activeConversation?.assigned_admin_id"
              size="small"
              color="primary"
              variant="tonal"
              :loading="assigning"
              prepend-icon="mdi-account-check"
              @click="assignMe"
            >
              Взять в работу
            </v-btn>
            <v-btn
              v-if="activeConversation?.status !== 'closed'"
              size="small"
              color="warning"
              variant="tonal"
              :loading="statusChanging"
              prepend-icon="mdi-chat-minus-outline"
              @click="closeConversation"
            >
              Закрыть
            </v-btn>
            <v-btn
              v-else
              size="small"
              color="success"
              variant="tonal"
              :loading="statusChanging"
              prepend-icon="mdi-chat-plus-outline"
              @click="reopenConversation"
            >
              Открыть
            </v-btn>
            <v-btn
              icon
              variant="text"
              density="compact"
              :loading="threadLoading"
              @click="refreshThread"
              title="Обновить"
            >
              <v-icon size="18">mdi-refresh</v-icon>
            </v-btn>
          </ButtonGroup>
          <div v-if="activeConversation?.assigned_admin_id" class="text-caption text-medium-emphasis d-flex align-center gap-1">
            <v-icon size="14">mdi-account-check</v-icon>
            {{ activeConversation.assigned_admin?.display_name ?? activeConversation.assigned_admin?.name ?? 'Назначен' }}
          </div>
        </div>

        <v-divider />

        <!-- Messages -->
        <div class="thread-messages-wrapper">
          <div ref="messagesEl" class="thread-messages overflow-y-auto" @scroll="onThreadScroll">
            <div v-if="threadLoading && messages.length === 0" class="thread-loading-state">
              <v-skeleton-loader v-for="i in 4" :key="i" type="text" :width="i % 2 ? '55%' : '70%'" />
            </div>

            <AppStateBlock
              v-else-if="messages.length === 0 && !threadLoading"
              icon="mdi-message-text-outline"
              title="Сообщений пока нет"
              description="История переписки появится после первого сообщения."
              density="compact"
            />

            <template v-else>
              <!-- Message search bar -->
              <div class="thread-search-bar">
                <v-text-field
                  v-model="messageSearch"
                  placeholder="Поиск по сообщениям…"
                  prepend-inner-icon="mdi-magnify"
                  density="compact"
                  variant="outlined"
                  hide-details
                  clearable
                  class="thread-search-field"
                />
              </div>

              <AppStateBlock
                v-if="filteredMessages.length === 0"
                icon="mdi-magnify-close"
                title="Ничего не найдено"
                description="Измените запрос поиска по сообщениям."
                density="compact"
              />

              <div v-else class="thread-message-stack">
                <template v-for="(msg, index) in filteredMessages" :key="msg.id">
                  <!-- Date separator -->
                  <div v-if="showDateSeparator(filteredMessages, index)" class="admin-date-separator">
                    <span class="admin-date-separator__label">{{ formatDateLabel(msg.created_at) }}</span>
                  </div>

                  <div
                    class="d-flex"
                    :class="msg.sender_role === 'admin' ? 'justify-end' : 'justify-start'"
                  >
                    <div
                      class="admin-chat-bubble text-body-2"
                      :class="msg.sender_role === 'admin' ? 'admin-chat-bubble--admin' : 'admin-chat-bubble--customer'"
                    >
                      <div
                        v-if="msg.sender_display_name"
                        class="admin-chat-bubble__sender"
                      >
                        {{ msg.sender_display_name }}
                      </div>
                      <div v-if="msg.body" class="admin-chat-bubble__body">{{ msg.body }}</div>
                      <!-- Attachments -->
                      <ChatAttachmentItem
                        v-for="att in (msg.attachments ?? [])"
                        :key="att.id"
                        :attachment="att"
                      />
                      <div class="admin-chat-bubble__time">
                        {{ formatTime(msg.created_at) }}
                      </div>
                      <v-btn
                        v-if="msg.sender_role === 'admin'"
                        icon
                        size="x-small"
                        variant="text"
                        class="admin-chat-bubble__delete"
                        :loading="deletingMessageId === msg.id"
                        title="Удалить сообщение"
                        @click="deleteAdminMessage(msg)"
                      >
                        <v-icon size="15">mdi-delete-outline</v-icon>
                      </v-btn>
                    </div>
                  </div>
                </template>
              </div>
            </template>

            <!-- User typing indicator -->
            <transition name="fade">
              <div v-if="isUserTyping" class="admin-typing-indicator">
                <div class="typing-dots">
                  <span /><span /><span />
                </div>
                <span class="typing-label">Пользователь печатает…</span>
              </div>
            </transition>
          </div>

          <!-- Scroll to bottom button -->
          <transition name="fade">
            <v-btn
              v-if="!isAtBottom"
              class="thread-scroll-btn"
              icon
              size="small"
              color="primary"
              variant="elevated"
              @click="scrollToBottom()"
            >
              <v-icon size="18">mdi-chevron-down</v-icon>
            </v-btn>
          </transition>
        </div>

        <v-divider />

        <!-- Send error -->
        <v-alert
          v-if="sendError"
          type="error"
          density="compact"
          variant="tonal"
          closable
          class="ma-2"
          @click:close="sendError = null"
        >
          {{ sendError }}
        </v-alert>

        <!-- Input -->
        <div
          v-if="activeConversation?.status !== 'closed'"
          class="chat-composer"
        >
          <!-- Hidden file picker -->
          <input
            ref="fileInputEl"
            type="file"
            accept="image/png,image/jpeg,image/gif,image/webp"
            style="display: none;"
            @change="onFileSelected"
          />

          <!-- Attached file preview chip -->
          <v-chip
            v-if="attachedFile"
            size="small"
            closable
            prepend-icon="mdi-paperclip"
            variant="tonal"
            class="chat-composer__attachment text-truncate"
            @click:close="attachedFile = null"
          >
            {{ attachedFile.name }}
          </v-chip>

          <div class="chat-composer__row">
            <!-- Paperclip button -->
            <v-btn
              icon
              variant="text"
              size="small"
              :disabled="sending"
              aria-label="Прикрепить файл"
              @click="fileInputEl?.click()"
            >
              <v-icon size="18">mdi-paperclip</v-icon>
            </v-btn>

            <v-textarea
              ref="textareaRef"
              v-model="replyText"
              placeholder="Ответить пользователю…"
              rows="1"
              auto-grow
              max-rows="5"
              density="compact"
              variant="outlined"
              hide-details
              class="flex-grow-1"
              :disabled="sending"
              @keydown.enter.exact.prevent="sendReply"
              @keydown="onAdminTypingKey"
              @paste="onPaste"
            />
            <v-btn
              icon
              color="primary"
              variant="tonal"
              size="small"
              :disabled="(!replyText.trim() && !attachedFile) || sending"
              :loading="sending"
              @click="sendReply"
            >
              <v-icon size="18">mdi-send</v-icon>
            </v-btn>
          </div>
        </div>

        <div v-else class="chat-closed-state">
          <StatusChip status="closed" label="Диалог закрыт" color="grey" size="small" />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import {
  adminChatApi,
  type AdminConversationMeta,
  type AdminConversationDetail,
} from '@/api/adminSupportChat'
import type { ChatMessage } from '@/api/supportChat'
import type { ConversationStatus } from '@/api/supportChat'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import ChatAttachmentItem from '@/components/support/ChatAttachmentItem.vue'

// ── Sound ─────────────────────────────────────────────────────────────────────
function playBeep(): void {
  try {
    const ctx = new AudioContext()
    const osc  = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.type = 'sine'
    osc.frequency.setValueAtTime(880, ctx.currentTime)
    gain.gain.setValueAtTime(0.12, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + 0.35)
  } catch { /* AudioContext not available */ }
}

// ── State ─────────────────────────────────────────────────────────────────────
const conversations  = ref<AdminConversationMeta[]>([])
const pagination     = ref({ total: 0, per_page: 20, current_page: 1, last_page: 1 })
const currentPage    = ref(1)
const statusFilter   = ref<ConversationStatus | ''>('')
const search         = ref('')
const unassignedOnly = ref(false)
const listLoading    = ref(false)
const adminAlias     = ref('')
const aliasLoading   = ref(false)
const aliasSaving    = ref(false)

const activeId           = ref<number | null>(null)
const activeConversation = ref<AdminConversationDetail | null>(null)
const messages           = ref<ChatMessage[]>([])
const threadLoading      = ref(false)
const sending            = ref(false)
const assigning          = ref(false)
const statusChanging     = ref(false)
const deletingMessageId  = ref<number | null>(null)
const replyText          = ref('')
const sendError          = ref<string | null>(null)
const isUserTyping       = ref(false)   // user is typing in this conversation
const messageSearch      = ref('')      // search within messages
const isAtBottom         = ref(true)    // scroll tracking

const messagesEl    = ref<HTMLElement | null>(null)
const textareaRef   = ref<InstanceType<typeof import('vuetify/components').VTextarea> | null>(null)
const fileInputEl   = ref<HTMLInputElement | null>(null)
const attachedFile  = ref<File | null>(null)

const totalUnreadCount = computed(() =>
  conversations.value.reduce((sum, conv) => sum + (conv.unread_count ?? 0), 0)
)

// ── Filtered messages (search) ────────────────────────────────────────────────
const filteredMessages = computed(() => {
  if (!messageSearch.value.trim()) return messages.value
  const q = messageSearch.value.toLowerCase()
  return messages.value.filter((m) =>
    m.body?.toLowerCase().includes(q) ||
    m.attachments?.some((a) => a.original_name.toLowerCase().includes(q))
  )
})

// ── Date separator helpers ────────────────────────────────────────────────────
function isSameDay(a: string, b: string): boolean {
  const da = new Date(a), db = new Date(b)
  return da.getFullYear() === db.getFullYear() &&
    da.getMonth() === db.getMonth() &&
    da.getDate() === db.getDate()
}

function showDateSeparator(list: ChatMessage[], index: number): boolean {
  if (index === 0) return true
  const previous = list[index - 1]
  const current = list[index]
  if (!previous || !current) return false
  return !isSameDay(previous.created_at, current.created_at)
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

// ── Scroll tracking ───────────────────────────────────────────────────────────
function onThreadScroll(): void {
  const el = messagesEl.value
  if (!el) return
  isAtBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80
}

// ── Polling ───────────────────────────────────────────────────────────────────
const POLL_INTERVAL      = 4_000
const LIST_POLL_INTERVAL = 60_000
let pollTimer:     ReturnType<typeof setInterval> | null = null
let listPollTimer: ReturnType<typeof setInterval> | null = null
let polling       = false
let listPolling   = false

// Admin typing heartbeat
let adminTypingTimer:      ReturnType<typeof setInterval> | null = null
let adminTypingStartTimer: ReturnType<typeof setTimeout>  | null = null  // 3-sec delay
let adminTypingStopTimer:  ReturnType<typeof setTimeout>  | null = null
let sendingTyping = false

const lastMessageId = computed(() => {
  const last = messages.value[messages.value.length - 1]
  return last?.id ?? 0
})

function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(poll, POLL_INTERVAL)
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

async function poll() {
  if (polling || !activeId.value) return
  polling = true
  try {
    const [msgResult, typingResult] = await Promise.allSettled([
      adminChatApi.getMessages(activeId.value, lastMessageId.value),
      adminChatApi.typingStatus(activeId.value),
    ])

    if (typingResult.status === 'fulfilled') {
      isUserTyping.value = typingResult.value.user_typing
    }

    if (msgResult.status === 'fulfilled' && msgResult.value.messages.length > 0) {
      const fresh = msgResult.value.messages
      messages.value.push(...fresh)
      // Play sound for messages from users (not admins)
      if (fresh.some((m) => m.sender_role !== 'admin')) {
        playBeep()
        // New message from user — hide typing indicator immediately
        isUserTyping.value = false
      }
      if (isAtBottom.value) scrollToBottom()
      await adminChatApi.markRead(activeId.value)
      const conv = conversations.value.find((c) => c.id === activeId.value)
      if (conv) conv.unread_count = 0
    }
  } catch { /* silent */ } finally { polling = false }
}

// List polling to keep unread counts fresh for non-active conversations
function startListPolling() {
  if (listPollTimer) return
  listPollTimer = setInterval(silentRefreshList, LIST_POLL_INTERVAL)
}

function stopListPolling() {
  if (listPollTimer) { clearInterval(listPollTimer); listPollTimer = null }
}

async function silentRefreshList() {
  if (listPolling) return
  listPolling = true
  try {
    // Save current unread counts before refreshing
    const prevCounts: Record<number, number> = {}
    conversations.value.forEach((c) => { prevCounts[c.id] = c.unread_count })

    const res = await adminChatApi.list({
      status:     statusFilter.value || undefined,
      unassigned: unassignedOnly.value || undefined,
      search:     search.value || undefined,
      page:       currentPage.value,
      per_page:   20,
    })

    // Sound if any non-active conversation has new unread messages
    let hasNewUnread = false
    res.conversations.forEach((conv) => {
      if (conv.id !== activeId.value && conv.unread_count > (prevCounts[conv.id] ?? 0)) {
        hasNewUnread = true
      }
    })
    if (hasNewUnread) playBeep()

    conversations.value = res.conversations
    pagination.value     = res.pagination
  } catch { /* silent */ } finally { listPolling = false }
}

// Admin typing heartbeat helpers
/**
 * Only starts the heartbeat after 3 s of continuous typing,
 * so short replies don't show a typing indicator to the user at all.
 */
function onAdminTypingKey(): void {
  if (!activeId.value) return

  // Reset inactivity stop timer
  if (adminTypingStopTimer) { clearTimeout(adminTypingStopTimer); adminTypingStopTimer = null }
  adminTypingStopTimer = setTimeout(() => stopAdminTyping(), 5_000)

  // Start delayed heartbeat only if not already running
  if (!adminTypingStartTimer && !adminTypingTimer) {
    adminTypingStartTimer = setTimeout(() => {
      adminTypingStartTimer = null
      sendAdminTypingHeartbeat()
      adminTypingTimer = setInterval(sendAdminTypingHeartbeat, 4_000)
    }, 3_000)
  }
}

async function sendAdminTypingHeartbeat(): Promise<void> {
  if (sendingTyping || !activeId.value) return
  sendingTyping = true
  try { await adminChatApi.reportTyping(activeId.value) }
  catch { /* silent */ }
  finally { sendingTyping = false }
}

function stopAdminTyping(): void {
  if (adminTypingStartTimer) { clearTimeout(adminTypingStartTimer);  adminTypingStartTimer = null }
  if (adminTypingTimer)      { clearInterval(adminTypingTimer);       adminTypingTimer      = null }
  if (adminTypingStopTimer)  { clearTimeout(adminTypingStopTimer);   adminTypingStopTimer  = null }
}

// ── Search debounce ───────────────────────────────────────────────────────────
let searchTimer: ReturnType<typeof setTimeout> | null = null
function debouncedSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { currentPage.value = 1; loadList() }, 400)
}

// ── Load list ─────────────────────────────────────────────────────────────────
async function loadList() {
  listLoading.value = true
  try {
    const res = await adminChatApi.list({
      status:      statusFilter.value || undefined,
      unassigned:  unassignedOnly.value || undefined,
      search:      search.value || undefined,
      page:        currentPage.value,
      per_page:    20,
    })
    conversations.value = res.conversations
    pagination.value = res.pagination
  } finally {
    listLoading.value = false
  }
}

async function loadAdminProfile() {
  aliasLoading.value = true
  try {
    const profile = await adminChatApi.profile()
    adminAlias.value = profile.admin_chat_alias ?? ''
  } finally {
    aliasLoading.value = false
  }
}

async function saveAdminAlias() {
  if (aliasSaving.value) return
  aliasSaving.value = true
  try {
    const profile = await adminChatApi.updateProfile(adminAlias.value.trim() || null)
    adminAlias.value = profile.admin_chat_alias ?? ''
    await refreshThread()
  } finally {
    aliasSaving.value = false
  }
}

// ── Select conversation ───────────────────────────────────────────────────────
async function selectConversation(id: number) {
  if (activeId.value === id) return
  stopPolling()
  activeId.value = id
  messages.value = []
  threadLoading.value = true
  sendError.value = null
  try {
    const { conversation } = await adminChatApi.show(id)
    activeConversation.value = conversation
    messages.value = conversation.messages
    scrollToBottom('instant')
    // Mark read
    await adminChatApi.markRead(id)
    const conv = conversations.value.find((c) => c.id === id)
    if (conv) conv.unread_count = 0
    startPolling()
  } catch {
    activeId.value = null
  } finally {
    threadLoading.value = false
  }
}

async function refreshThread() {
  if (!activeId.value) return
  threadLoading.value = true
  try {
    const { conversation } = await adminChatApi.show(activeId.value)
    activeConversation.value = conversation
    messages.value = conversation.messages
    scrollToBottom()
  } finally {
    threadLoading.value = false }
}

// ── Send reply ────────────────────────────────────────────────────────────────
async function sendReply() {
  const body = replyText.value.trim()
  const file = attachedFile.value
  if (!body && !file) return
  if (!activeId.value || sending.value) return
  stopAdminTyping()
  sending.value = true
  sendError.value = null
  try {
    const { message } = await adminChatApi.sendMessage(activeId.value, {
      body: body || undefined,
      file: file ?? undefined,
    })
    messages.value.push(message)
    replyText.value = ''
    attachedFile.value = null
    scrollToBottom()
    // Refocus textarea after send
    nextTick(() => textareaRef.value?.focus())
    // Refresh conversation meta to reflect assigned admin
    const { conversation } = await adminChatApi.show(activeId.value)
    activeConversation.value = conversation
  } catch {
    sendError.value = 'Не удалось отправить сообщение.'
  } finally {
    sending.value = false
  }
}

// ── File attachment ───────────────────────────────────────────────────────────
function onFileSelected(e: Event): void {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  attachedFile.value = file
  input.value = '' // allow re-selecting same file
}

function onPaste(e: ClipboardEvent): void {
  const items = e.clipboardData?.items
  if (!items) return
  for (const item of Array.from(items)) {
    if (item.kind === 'file' && item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) { attachedFile.value = file; e.preventDefault(); break }
    }
  }
}

// ── Assign ────────────────────────────────────────────────────────────────────
async function assignMe() {
  if (!activeId.value || assigning.value) return
  assigning.value = true
  try {
    const res = await adminChatApi.assignMe(activeId.value)
    if (activeConversation.value) {
      activeConversation.value.assigned_admin_id = res.assigned_admin_id
      activeConversation.value.assigned_admin = res.assigned_admin
    }
  } finally {
    assigning.value = false
  }
}

async function closeConversation() {
  if (!activeId.value || statusChanging.value) return
  statusChanging.value = true
  try {
    const { conversation } = await adminChatApi.close(activeId.value)
    activeConversation.value = conversation
    messages.value = conversation.messages
    updateConversationMeta(conversation)
  } finally {
    statusChanging.value = false
  }
}

async function reopenConversation() {
  if (!activeId.value || statusChanging.value) return
  statusChanging.value = true
  try {
    const { conversation } = await adminChatApi.reopen(activeId.value)
    activeConversation.value = conversation
    messages.value = conversation.messages
    updateConversationMeta(conversation)
  } finally {
    statusChanging.value = false
  }
}

async function deleteAdminMessage(message: ChatMessage) {
  if (!activeId.value || deletingMessageId.value) return
  deletingMessageId.value = message.id
  try {
    await adminChatApi.deleteMessage(activeId.value, message.id)
    messages.value = messages.value.filter((item) => item.id !== message.id)
    await silentRefreshList()
  } finally {
    deletingMessageId.value = null
  }
}

function updateConversationMeta(conversation: AdminConversationDetail) {
  const index = conversations.value.findIndex((item) => item.id === conversation.id)
  if (index === -1) return

  conversations.value[index] = {
    id: conversation.id,
    status: conversation.status,
    subject: conversation.subject,
    assigned_admin_id: conversation.assigned_admin_id,
    assigned_admin: conversation.assigned_admin,
    creator: conversation.creator,
    last_message_at: conversation.last_message_at,
    unread_count: conversation.unread_count,
    created_at: conversation.created_at,
  }
}

// ── Scroll ────────────────────────────────────────────────────────────────────
function scrollToBottom(behavior: ScrollBehavior = 'smooth') {
  nextTick(() => {
    const el = messagesEl.value
    if (el) el.scrollTo({ top: el.scrollHeight, behavior })
  })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const STATUS_LABELS: Record<string, string> = {
  open: 'Открыт', pending: 'Ожидание', closed: 'Закрыт',
}
const STATUS_COLORS: Record<string, string> = {
  open: 'success', pending: 'warning', closed: 'default',
}

function statusLabel(s: string) { return STATUS_LABELS[s] ?? s }
function statusColor(s: string) { return STATUS_COLORS[s] ?? 'grey' }

function formatTime(iso: string) {
  return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function relativeTime(iso: string | null): string {
  if (!iso) return ''
  const diff = Date.now() - new Date(iso).getTime()
  const m = Math.floor(diff / 60000)
  if (m < 1)  return 'только что'
  if (m < 60) return `${m} мин`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} ч`
  return `${Math.floor(h / 24)} д`
}

// ── Watchers ──────────────────────────────────────────────────────────────────
watch(statusFilter, () => { currentPage.value = 1; loadList() })

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(() => {
  loadList()
  loadAdminProfile()
  startListPolling()
})

onUnmounted(() => {
  stopPolling()
  stopListPolling()
  stopAdminTyping()
  if (searchTimer) clearTimeout(searchTimer)
})
</script>

<style scoped>
.admin-chat-view {
  display: flex;
  gap: var(--ds-space-12);
  height: calc(100dvh - 88px);
  padding: var(--ds-space-12);
  overflow: hidden;
  background: rgb(var(--v-theme-background));
}

.chat-list-panel {
  width: 340px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgb(var(--v-theme-surface));
}

.chat-list-scroll {
  flex: 1;
  min-height: 0;
  padding: var(--ds-space-6);
  background: color-mix(in srgb, rgb(var(--v-theme-surface-container-low)) 42%, transparent);
}

.chat-thread-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgb(var(--v-theme-surface));
}

.chat-shell-divider {
  display: none;
}

.chat-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-12);
  padding: var(--ds-space-14) var(--ds-space-16) var(--ds-space-10);
  border-bottom: 1px solid var(--ds-border-color);
  background: rgb(var(--v-theme-surface-container-low));
}

.chat-panel-title {
  color: var(--ds-text-primary);
  font-size: 0.95rem;
  font-weight: 700;
  line-height: 1.35;
}

.chat-panel-subtitle {
  margin-top: var(--ds-space-2);
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
  line-height: 1.35;
}

.chat-list-filters {
  display: grid;
  gap: var(--ds-space-10);
  padding: var(--ds-space-12);
}

.chat-status-filter {
  margin: calc(var(--ds-space-4) * -1);
}

.chat-thread-empty {
  display: grid;
  height: 100%;
  place-items: center;
  padding: var(--ds-space-24);
}

.thread-header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: var(--ds-space-12);
  padding: var(--ds-space-12) var(--ds-space-16);
  background: rgb(var(--v-theme-surface-container-low));
}

.thread-header__title {
  color: var(--ds-text-primary);
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.35;
}

.thread-header__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--ds-space-6);
  margin-top: var(--ds-space-2);
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
  line-height: 1.35;
}

.thread-header__actions {
  flex-shrink: 0;
}

.thread-messages-wrapper {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  position: relative;
}

.thread-messages {
  flex: 1;
  min-height: 0;
  background: color-mix(in srgb, rgb(var(--v-theme-surface-container-low)) 56%, transparent);
}

.thread-loading-state {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-12);
  padding: var(--ds-space-16);
}

/* Scroll to bottom button */
.thread-scroll-btn {
  position: absolute;
  bottom: 12px;
  right: 12px;
  z-index: 10;
  box-shadow: none !important;
}

/* User typing indicator */
.admin-typing-indicator {
  display: flex;
  align-items: center;
  gap: var(--ds-space-6);
  padding: var(--ds-space-4) var(--ds-space-16) var(--ds-space-10);
  color: var(--ds-text-secondary);
}

.typing-label {
  font-size: 0.78rem;
  color: var(--ds-text-secondary);
  font-style: italic;
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

/* Message search bar */
.thread-search-bar {
  padding: var(--ds-space-10) var(--ds-space-12);
  border-bottom: 1px solid var(--ds-border-color);
  background: rgb(var(--v-theme-surface-container-low));
}

.thread-search-field {
  font-size: 0.85rem;
}

/* Date separators */
.admin-date-separator {
  display: flex;
  align-items: center;
  gap: var(--ds-space-8);
  margin: var(--ds-space-12) 0 var(--ds-space-6);
}

.admin-date-separator::before,
.admin-date-separator::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--ds-border-color);
}

.admin-date-separator__label {
  font-size: 0.7rem;
  color: var(--ds-text-secondary);
  font-weight: 500;
  letter-spacing: 0;
  text-transform: uppercase;
  white-space: nowrap;
  padding: 0 4px;
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }

.conv-item {
  cursor: pointer;
  border-radius: var(--ds-radius-12);
  margin-bottom: var(--ds-space-4);
  padding-inline: var(--ds-space-6);
}

.conv-item :deep(.v-list-item__overlay) {
  border-radius: var(--ds-radius-12);
}

.thread-message-stack {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-8);
  padding: var(--ds-space-14);
}

.admin-chat-bubble {
  position: relative;
  max-width: 72%;
  padding: var(--ds-space-10) var(--ds-space-12);
  border-radius: var(--ds-radius-16);
  word-break: break-word;
  line-height: 1.45;
  border: 1px solid transparent;
}

.admin-chat-bubble--customer {
  color: var(--ds-text-primary);
  border-color: var(--ds-border-color);
  border-bottom-left-radius: var(--ds-radius-6);
  background: rgb(var(--v-theme-surface));
}

.admin-chat-bubble--admin {
  border-bottom-right-radius: var(--ds-radius-6);
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.admin-chat-bubble__sender {
  margin-bottom: var(--ds-space-4);
  color: var(--ds-text-secondary);
  font-size: 0.76rem;
  font-weight: 700;
  line-height: 1.35;
}

.admin-chat-bubble--admin .admin-chat-bubble__sender,
.admin-chat-bubble--admin .admin-chat-bubble__time {
  color: color-mix(in srgb, rgb(var(--v-theme-on-primary)) 74%, transparent);
}

.admin-chat-bubble__body {
  white-space: pre-wrap;
}

.admin-chat-bubble__time {
  margin-top: var(--ds-space-6);
  color: var(--ds-text-secondary);
  font-size: 0.68rem;
  line-height: 1.25;
  text-align: right;
}

.admin-chat-bubble__delete {
  position: absolute;
  inset-block-start: 2px;
  inset-inline-end: 2px;
  opacity: 0;
}

.admin-chat-bubble:hover .admin-chat-bubble__delete,
.admin-chat-bubble__delete:focus-visible {
  opacity: 1;
}

.thread-header__alias {
  width: 180px;
}

.chat-composer {
  display: grid;
  gap: var(--ds-space-8);
  padding: var(--ds-space-10) var(--ds-space-12);
  border-top: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-low), 0.76);
}

.chat-composer__attachment {
  max-width: 100%;
  justify-self: start;
}

.chat-composer__row {
  display: flex;
  align-items: flex-end;
  gap: var(--ds-space-8);
}

.chat-closed-state {
  display: flex;
  justify-content: center;
  padding: var(--ds-space-12);
  border-top: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-low), 0.76);
}

@media (max-width: 960px) {
  .admin-chat-view {
    flex-direction: column;
    height: auto;
    min-height: calc(100dvh - 88px);
    overflow: visible;
  }

  .chat-list-panel {
    width: 100%;
    max-height: 42vh;
  }

  .chat-thread-panel {
    min-height: 58vh;
  }

  .thread-header {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .thread-header__actions {
    width: 100%;
  }
}

@media (max-width: 600px) {
  .admin-chat-view {
    gap: var(--ds-space-8);
    padding: var(--ds-space-8);
  }

  .chat-list-panel {
    max-height: 48vh;
  }

  .thread-header {
    padding: var(--ds-space-12);
  }

  .admin-chat-bubble {
    max-width: 88%;
  }

  .chat-composer__row {
    align-items: stretch;
  }
}
</style>
