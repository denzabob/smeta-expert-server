<template>
  <div class="expert-chat">
    <section class="expert-chat__main">
      <header class="expert-chat__toolbar">
        <v-menu location="bottom start">
          <template #activator="{ props: menuProps }">
            <v-btn v-bind="menuProps" variant="text" append-icon="mdi-chevron-down" class="expert-chat__conversation">
              {{ conversation?.title || 'Общий анализ' }}
            </v-btn>
          </template>
          <v-list density="compact" min-width="240">
            <v-list-subheader>Чаты проекта</v-list-subheader>
            <v-list-item
              v-for="item in project.conversations"
              :key="item.id"
              :title="item.title"
              prepend-icon="mdi-message-text-outline"
              @click="conversationId = item.id"
            />
            <v-divider />
            <v-list-item title="Новый чат" prepend-icon="mdi-plus" @click="openNewConversation" />
          </v-list>
        </v-menu>
        <div class="expert-chat__toolbar-actions">
          <v-tooltip v-if="projectMode === 'demo'" text="Контекст проекта">
            <template #activator="{ props: tooltipProps }">
              <v-btn v-bind="tooltipProps" icon="mdi-dock-right" size="small" :variant="contextOpen ? 'tonal' : 'text'" aria-label="Контекст проекта" @click="contextOpen = !contextOpen" />
            </template>
          </v-tooltip>
        </div>
      </header>

      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="ma-3">
        <span>{{ errorMessage }}</span>
        <template #append><v-btn size="small" variant="text" @click="retryHistory">Повторить</v-btn></template>
      </v-alert>

      <div class="expert-chat__messages-wrap">
        <div ref="messageArea" class="expert-chat__messages" tabindex="0" @scroll.passive="updateScrollPosition" @wheel.passive="markUserScrollIntent" @touchmove.passive="markUserScrollIntent" @pointerdown="markPointerScrollIntent" @keydown="markKeyboardScrollIntent">
          <div v-if="!loading && !messages.length" class="expert-chat__empty">
            <div class="expert-chat__empty-icon"><v-icon :icon="projectMode === 'demo' ? 'mdi-prism' : 'mdi-message-text-outline'" size="30" /></div>
            <h1>{{ projectMode === 'demo' ? 'Чем помочь в этом исследовании?' : 'Экспертный чат' }}</h1>
            <p>{{ projectMode === 'demo' ? 'Prism AI работает с демонстрационным контекстом проекта.' : 'Начните рабочий диалог — сообщения сохранятся в истории проекта.' }}</p>
            <div v-if="projectMode === 'demo'" class="expert-chat__quick-actions">
              <button v-for="action in project.quickActions" :key="action" type="button" @click="sendMessage(action)">
                <v-icon icon="mdi-arrow-up-right" size="17" /><span>{{ action }}</span>
              </button>
            </div>
          </div>
          <ExpertChatMessage
            v-for="message in messages"
            v-else
            :key="message.id"
            :message="message"
            :allow-continue="Boolean(continuableAssistantIds[message.id]) || message.generationStatus === 'stopped' || message.generationStatus === 'interrupted'"
            :image-previews="transfers.thumbnailPreviews.value"
            :timeline-runs="timelineRunsFor(message.id)"
            :show-slow-waiting="showSlowWaitingFor(message.id)"
            :feedback-enabled="projectMode === 'real'"
            @action="notify"
            @open-source="contextOpen = true"
            @open-material="openMessageMaterial"
            @retry="retryMessage"
            @continue="continueMessage"
            @feedback-updated="updateMessageFeedback"
          />
        </div>
        <v-btn v-if="showScrollToBottom" class="expert-chat__new-messages" color="surface" variant="flat" size="small" append-icon="mdi-arrow-down" aria-label="Показать новые сообщения" @click="scrollToLatest('smooth')">Новые сообщения</v-btn>
      </div>

      <ExpertChatComposer
        :context-chips="projectMode === 'demo' ? contextChips : []"
        :persistence-only="projectMode === 'real'"
        :allow-whole-project-context="projectMode === 'demo'"
        :allow-file-upload="projectMode === 'real'"
        :upload-items="composerUploadItems"
        :material-contexts="composerMaterialContexts"
        :image-previews="composerImagePreviews"
        :send-blocked-reason="composerSendBlockedReason"
        :busy="streamActive"
        @send="sendMessage"
        @stop="stopStream"
        @attachment="handleAttachment"
        @attach-files="attachComposerFiles"
        @retry-upload="retryComposerUpload"
        @remove-upload="removeFailedComposerUpload"
        @remove-material-context="removeComposerMaterialContext"
        @remove-context="removeContext"
        @select-whole-project="selectWholeProject"
      />
    </section>

    <ExpertContextPanel v-if="projectMode === 'demo' && contextOpen && !mdAndDown" :project="project" @close="contextOpen = false" @action="notify" />
    <v-navigation-drawer v-if="projectMode === 'demo' && mdAndDown" v-model="contextOpen" temporary location="right" width="360">
      <ExpertContextPanel :project="project" @close="contextOpen = false" @action="notify" />
    </v-navigation-drawer>

    <v-dialog v-model="newConversationOpen" max-width="460">
      <v-card>
        <v-card-title>Новый чат</v-card-title>
        <v-card-text>
          <v-text-field v-model="newConversationTitle" label="Название" variant="outlined" autofocus @keydown.enter.prevent="createConversation" />
          <v-alert v-if="conversationError" type="error" variant="tonal" density="compact">{{ conversationError }}</v-alert>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn :disabled="creatingConversation" @click="newConversationOpen = false">Отмена</v-btn><v-btn color="primary" :loading="creatingConversation" @click="createConversation">Создать</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="libraryOpen" max-width="760" scrollable>
      <v-card v-if="libraryLoading"><v-card-title>Библиотека проекта</v-card-title><v-card-text><v-progress-linear indeterminate /></v-card-text></v-card>
      <ExpertProjectLibraryPicker v-else-if="libraryOpen" :materials="project.materials" :initially-selected="composerMaterialContexts.map((context) => context.id)" @cancel="libraryOpen = false" @confirm="confirmLibrarySelection" />
    </v-dialog>
    <ExpertMaterialDrawer v-if="materialDrawerOpen" v-model="materialDrawerOpen" :material="selectedMaterial" :project-mode="projectMode" :image-preview="selectedMaterial ? transfers.imagePreviews.value[selectedMaterial.id] : undefined" :downloading="selectedMaterial ? transfers.isDownloading(selectedMaterial.id) : false" @action="handleMaterialAction" />

    <v-snackbar v-model="snackbarOpen" :timeout="2600">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import type { Pinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import ExpertChatComposer from '../components/chat/ExpertChatComposer.vue'
import ExpertChatMessage from '../components/chat/ExpertChatMessage.vue'
import ExpertProjectLibraryPicker from '../components/chat/ExpertProjectLibraryPicker.vue'
import ExpertMaterialDrawer from '../components/materials/ExpertMaterialDrawer.vue'
import ExpertContextPanel from '../components/chat/ExpertContextPanel.vue'
import { createWholeProjectContext, removeChatContext, selectWholeProjectChatContext, type ExpertChatContextChip } from '../chatContext'
import { addExpertMessageMaterialContext, getExpertChatAttachmentSendBlockReason, mergeExpertMessageMaterialContexts, snapshotExpertMessageMaterialContext } from '../chatAttachments'
import { normalizeExpertChatDraft } from '../chatComposer'
import { shouldUseLegacyExpertChatFallback } from '../chatStreamingFallback'
import { appendUniqueExpertMessage, createOptimisticUserMessage, replaceOptimisticExpertMessage, setExpertMessageDeliveryState } from '../chatMessageState'
import { isNearExpertChatBottom, nextExpertChatFollowState } from '../chatScroll'
import {
  addExpertTimelineRun,
  appendExpertReasoningSummary,
  applyExpertTimelineActivity,
  finishExpertTimelineRun,
  EXPERT_SLOW_FIRST_TOKEN_MS,
  EXPERT_SIGNIFICANT_WAIT_MS,
  markExpertTimelineSignificant,
  moveExpertTimelineRuns,
  removeExpertTimelineRuns,
  type ExpertReasoningSummaryEvent,
  type ExpertRunActivity,
  type ExpertTimelineRun,
} from '../chatTimeline'
import { useExpertMaterialTransfers } from '../composables/useExpertMaterialTransfers'
import { expertLastConversationStorageKey, readExpertLastConversation, selectInitialExpertConversation, writeExpertLastConversation } from '../lastConversation'
import { expertApi, isExpertMaterialContextError, mapExpertApiError } from '../api'
import type { ExpertConversation, ExpertMessage, ExpertMessageFeedback, ExpertMessageMaterialContext, ExpertProject, ExpertProjectMaterial, ExpertProjectMode, ExpertRunDiagnostic } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const { mdAndDown } = useDisplay()
const activePinia = getCurrentInstance()?.appContext.config.globalProperties.$pinia as Pinia | undefined
const authStore = activePinia ? useAuthStore(activePinia) : null
const conversationId = ref('')
const messagesByConversation = ref<Record<string, ExpertMessage[]>>({})
const pendingMessages = ref<ExpertMessage[]>([])
const contextOpen = ref(props.projectMode === 'demo' && !mdAndDown.value)
const contextChips = ref<ExpertChatContextChip[]>(createWholeProjectContext())
const loading = ref(false)
const errorMessage = ref('')
const newConversationOpen = ref(false)
const newConversationTitle = ref('Общий анализ')
const creatingConversation = ref(false)
const conversationError = ref('')
const snackbarOpen = ref(false)
const snackbarText = ref('')
const messageArea = ref<HTMLElement | null>(null)
const showScrollToBottom = ref(false)
const followActiveResponse = ref(true)
let userScrollIntentUntil = 0
let followFrame = 0
const transfers = useExpertMaterialTransfers()
const composerMaterialContexts = ref<ExpertMessageMaterialContext[]>([])
const libraryOpen = ref(false)
const libraryLoading = ref(false)
const materialDrawerOpen = ref(false)
const selectedMaterial = ref<ExpertProjectMaterial | null>(null)
const composerUploadItems = computed(() => transfers.uploads.value)
const composerImagePreviews = computed(() => transfers.imagePreviews.value)
const composerSendBlockedReason = computed(() => getExpertChatAttachmentSendBlockReason(
  composerUploadItems.value,
  composerMaterialContexts.value,
))
const generationState = ref<'idle' | 'starting' | 'streaming' | 'stopping' | 'completed' | 'stopped' | 'interrupted' | 'error'>('idle')
const activeRunId = ref<string | null>(null)
const activeAbort = ref<AbortController | null>(null)
const continuableAssistantIds = ref<Record<string, true>>({})
const timelineByAssistant = ref<Record<string, ExpertTimelineRun[]>>({})
const slowWaitingRunIds = ref<Record<string, true>>({})
const slowWaitingTimers = new Map<string, ReturnType<typeof setTimeout>>()
const significantWaitTimers = new Map<string, ReturnType<typeof setTimeout>>()
const streamActive = computed(() => ['starting', 'streaming', 'stopping'].includes(generationState.value))
let conversationsSequence = 0
let messagesSequence = 0
let conversationCreationPromise: Promise<string> | null = null
let conversationsLoadPromise: Promise<void> | null = null

function resetTransientGenerationState() {
  generationState.value = 'idle'
  activeRunId.value = null
  activeAbort.value = null
  clearAllSlowWaiting()
}

const conversation = computed(() => props.project.conversations.find((item) => item.id === conversationId.value))
const lastConversationStorageKey = computed(() => {
  const userId = authStore?.user?.id
  return userId === undefined || userId === null || props.projectMode !== 'real'
    ? null
    : expertLastConversationStorageKey(userId, props.project.id)
})
const messages = computed(() => conversationId.value
  ? messagesByConversation.value[conversationId.value] ?? conversation.value?.messages ?? []
  : pendingMessages.value)

function appendMessages(newMessages: ExpertMessage[]) {
  const id = conversationId.value
  if (!id) {
    pendingMessages.value = [...pendingMessages.value, ...newMessages]
    return
  }
  messagesByConversation.value = {
    ...messagesByConversation.value,
    [id]: [...(messagesByConversation.value[id] ?? conversation.value?.messages ?? []), ...newMessages],
  }
}

function updateMessageDelivery(messageId: string, deliveryState: NonNullable<ExpertMessage['deliveryState']>, deliveryError?: string) {
  pendingMessages.value = setExpertMessageDeliveryState(pendingMessages.value, messageId, deliveryState, deliveryError)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [
    id,
    setExpertMessageDeliveryState(items, messageId, deliveryState, deliveryError),
  ]))
}

function updateMessageDiagnostic(messageId: string, diagnostic?: ExpertRunDiagnostic) {
  const update = (items: ExpertMessage[]) => items.map((message) => message.id === messageId ? { ...message, diagnostic } : message)
  pendingMessages.value = update(pendingMessages.value)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [id, update(items)]))
}

function updateMessageFeedback(messageId: string, feedback: ExpertMessageFeedback | null) {
  const update = (items: ExpertMessage[]) => items.map((message) => message.id === messageId ? { ...message, feedback } : message)
  pendingMessages.value = update(pendingMessages.value)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [id, update(items)]))
}

function replaceOptimisticMessage(optimisticId: string, savedMessage: ExpertMessage) {
  const optimistic = findMessage(optimisticId)
  const savedWithRuntime: ExpertMessage = {
    ...savedMessage,
    ...(savedMessage.role === 'user' ? { deliveryState: 'sent' as const } : {}),
    ...(optimistic?.clientMessageId ? { clientMessageId: optimistic.clientMessageId } : {}),
    ...(optimistic?.runtimeMaterialContext ? { runtimeMaterialContext: optimistic.runtimeMaterialContext } : {}),
  }
  if (savedMessage.role === 'user') {
    for (const attachment of savedMessage.attachments ?? []) {
      if (attachment.available && attachment.kind === 'image') void transfers.loadImageThumbnail({ id: attachment.id, kind: 'image' })
    }
  }
  pendingMessages.value = replaceOptimisticExpertMessage(pendingMessages.value, optimisticId, savedWithRuntime)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [
    id, replaceOptimisticExpertMessage(items, optimisticId, savedWithRuntime),
  ]))
}

function replaceSavedMessage(messageId: string, savedMessage: ExpertMessage) {
  const replace = (items: ExpertMessage[]) => items.map((message) => message.id === messageId ? { ...savedMessage, deliveryState: 'sent' as const } : message)
  pendingMessages.value = replace(pendingMessages.value)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [id, replace(items)]))
}

function appendServerAssistantMessage(targetConversationId: string, assistantMessage: ExpertMessage) {
  messagesByConversation.value = {
    ...messagesByConversation.value,
    [targetConversationId]: appendUniqueExpertMessage(
      messagesByConversation.value[targetConversationId] ?? [],
      assistantMessage,
    ),
  }
}

function updateMessageText(messageId: string, append: string) {
  const update = (items: ExpertMessage[]) => items.map((item) => item.id === messageId ? { ...item, text: item.text + append } : item)
  pendingMessages.value = update(pendingMessages.value)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [id, update(items)]))
}

function removeMessage(messageId: string) {
  pendingMessages.value = pendingMessages.value.filter((message) => message.id !== messageId)
  messagesByConversation.value = Object.fromEntries(Object.entries(messagesByConversation.value).map(([id, items]) => [
    id, items.filter((message) => message.id !== messageId),
  ]))
}

function timelineRunsFor(assistantId: string): ExpertTimelineRun[] {
  return timelineByAssistant.value[assistantId] ?? []
}

function beginTimelineRun(assistantId: string, runId: string, materialCount = 0) {
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: materialCount > 1
      ? markExpertTimelineSignificant(addExpertTimelineRun(timelineRunsFor(assistantId), runId), runId)
      : addExpertTimelineRun(timelineRunsFor(assistantId), runId),
  }
  significantWaitTimers.set(runId, setTimeout(() => {
    significantWaitTimers.delete(runId)
    markTimelineSignificant(assistantId, runId)
  }, EXPERT_SIGNIFICANT_WAIT_MS))
  const timer = setTimeout(() => {
    slowWaitingTimers.delete(runId)
    if (timelineRunsFor(assistantId).some((run) => run.runId === runId && run.terminal === undefined)) {
      slowWaitingRunIds.value = { ...slowWaitingRunIds.value, [runId]: true }
    }
  }, EXPERT_SLOW_FIRST_TOKEN_MS)
  slowWaitingTimers.set(runId, timer)
}

function markTimelineSignificant(assistantId: string, runId: string) {
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: markExpertTimelineSignificant(timelineRunsFor(assistantId), runId),
  }
}

function clearSignificantWait(runId: string) {
  const timer = significantWaitTimers.get(runId)
  if (timer !== undefined) clearTimeout(timer)
  significantWaitTimers.delete(runId)
}

function clearSlowWaiting(runId: string) {
  const timer = slowWaitingTimers.get(runId)
  if (timer !== undefined) clearTimeout(timer)
  slowWaitingTimers.delete(runId)
  if (!slowWaitingRunIds.value[runId]) return
  const { [runId]: removed, ...remaining } = slowWaitingRunIds.value
  void removed
  slowWaitingRunIds.value = remaining
}

function showSlowWaitingFor(assistantId: string): boolean {
  return timelineRunsFor(assistantId).some((run) => run.terminal === undefined && slowWaitingRunIds.value[run.runId] === true)
}

function clearAllSlowWaiting() {
  for (const runId of [...slowWaitingTimers.keys()]) clearSlowWaiting(runId)
  for (const runId of [...significantWaitTimers.keys()]) clearSignificantWait(runId)
  slowWaitingRunIds.value = {}
}

function applyTimelineActivity(assistantId: string, activity: ExpertRunActivity) {
  if (activity.code.startsWith('pdf.ocr.') || activity.code.startsWith('pdf.text.') || activity.code.startsWith('pdf.text_cache.') || activity.code.startsWith('tool.') || activity.code.startsWith('web.')) {
    markTimelineSignificant(assistantId, activity.runId)
  }
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: applyExpertTimelineActivity(timelineRunsFor(assistantId), activity),
  }
}

function appendTimelineSummary(assistantId: string, summary: ExpertReasoningSummaryEvent) {
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: appendExpertReasoningSummary(timelineRunsFor(assistantId), summary),
  }
}

function finishTimelineRun(assistantId: string, runId: string, terminal: 'completed' | 'cancelled' | 'interrupted') {
  clearSlowWaiting(runId)
  clearSignificantWait(runId)
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: finishExpertTimelineRun(timelineRunsFor(assistantId), runId, terminal),
  }
}

function moveTimelineRun(fromAssistantId: string, toAssistantId: string) {
  timelineByAssistant.value = moveExpertTimelineRuns(timelineByAssistant.value, fromAssistantId, toAssistantId)
}

function discardTimelineRun(assistantId: string) {
  timelineByAssistant.value = removeExpertTimelineRuns(timelineByAssistant.value, assistantId)
}

function findMessage(messageId: string): ExpertMessage | undefined {
  return pendingMessages.value.find((message) => message.id === messageId)
    ?? Object.values(messagesByConversation.value).flat().find((message) => message.id === messageId)
}

function attachConversation(created: ExpertConversation) {
  if (!props.project.conversations.some((item) => item.id === created.id)) {
    props.project.conversations.push(created)
    props.project.counts && (props.project.counts.conversations = props.project.conversations.length)
  }
  const queued = pendingMessages.value
  messagesByConversation.value = {
    ...messagesByConversation.value,
    [created.id]: [...queued, ...(messagesByConversation.value[created.id] ?? [])],
  }
  pendingMessages.value = []
  conversationId.value = created.id
}

async function ensureConversation(): Promise<string> {
  if (conversationId.value) return conversationId.value
  if (conversationsLoadPromise) {
    await conversationsLoadPromise
    if (conversationId.value) return conversationId.value
  }
  if (!conversationCreationPromise) {
    conversationCreationPromise = expertApi.createConversation(props.project.id, 'Общий анализ')
      .then((created) => {
        attachConversation(created)
        return created.id
      })
      .finally(() => { conversationCreationPromise = null })
  }
  return conversationCreationPromise
}

async function loadConversations() {
  const sequence = ++conversationsSequence
  const targetProject = props.project
  messagesByConversation.value = {}
  pendingMessages.value = []
  timelineByAssistant.value = {}
  errorMessage.value = ''
  if (props.projectMode === 'demo') {
    conversationId.value = props.project.conversations[0]?.id ?? ''
    await scrollToLatest('auto')
    return
  }
  loading.value = true
  try {
    const loaded = await expertApi.listConversations(targetProject.id)
    if (sequence !== conversationsSequence) return
    targetProject.conversations = loaded
    targetProject.counts && (targetProject.counts.conversations = loaded.length)
    const storageKey = lastConversationStorageKey.value
    const lastOpenedId = storageKey ? readExpertLastConversation(window.localStorage, storageKey) : null
    conversationId.value = selectInitialExpertConversation(loaded, lastOpenedId)
  } catch (error) {
    if (sequence === conversationsSequence) errorMessage.value = mapExpertApiError(error).message
  } finally {
    if (sequence === conversationsSequence) loading.value = false
  }
}

async function loadMessages(id: string, force = false) {
  if (props.projectMode === 'demo' || !id || (!force && Object.prototype.hasOwnProperty.call(messagesByConversation.value, id))) return
  const sequence = ++messagesSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const loaded = await expertApi.listMessages(id)
    if (sequence !== messagesSequence) return
    const repliedTo = new Set(loaded.filter((message) => message.role === 'assistant').map((message) => message.metadata?.in_reply_to).filter((value): value is string => typeof value === 'string'))
    const history = loaded.map((message) => message.role === 'user' && !repliedTo.has(message.id)
      ? { ...message, deliveryState: 'error' as const, deliveryError: 'Ответ не получен. Можно повторить запрос.' }
      : message)
    messagesByConversation.value = { ...messagesByConversation.value, [id]: history }
    for (const message of history) {
      for (const attachment of message.attachments ?? []) {
        if (attachment.available && attachment.kind === 'image') void transfers.loadImageThumbnail({ id: attachment.id, kind: 'image' })
      }
    }
    await scrollToLatest('auto')
  } catch (error) {
    if (sequence === messagesSequence) errorMessage.value = mapExpertApiError(error).message
  } finally {
    if (sequence === messagesSequence) loading.value = false
  }
}

async function retryHistory() {
  errorMessage.value = ''
  if (conversationId.value) {
    await loadMessages(conversationId.value, true)
    return
  }
  await requestConversations()
}

function requestConversations(): Promise<void> {
  const request = loadConversations()
  conversationsLoadPromise = request
  void request.finally(() => {
    if (conversationsLoadPromise === request) conversationsLoadPromise = null
  })
  return request
}

function openNewConversation() {
  if (props.projectMode === 'demo') {
    notify('Новый чат в демо-режиме')
    return
  }
  conversationError.value = ''
  newConversationTitle.value = 'Общий анализ'
  newConversationOpen.value = true
}

async function createConversation() {
  const title = newConversationTitle.value.trim()
  if (!title) {
    conversationError.value = 'Укажите название чата.'
    return
  }
  creatingConversation.value = true
  conversationError.value = ''
  try {
    const created = await expertApi.createConversation(props.project.id, title)
    attachConversation(created)
    newConversationOpen.value = false
    await scrollToLatest('auto')
  } catch (error) {
    conversationError.value = mapExpertApiError(error).message
  } finally {
    creatingConversation.value = false
  }
}

function restoreMessageMaterialContext(message: ExpertMessage) {
  if (!message.runtimeMaterialContext) return
  composerMaterialContexts.value = mergeExpertMessageMaterialContexts(
    composerMaterialContexts.value,
    message.runtimeMaterialContext,
  )
}

async function persistMessage(message: ExpertMessage) {
  if (!message.clientMessageId) {
    updateMessageDelivery(message.id, 'error', 'Не удалось подготовить идентификатор сообщения для повторной отправки.')
    return
  }

  let persistedUserId = message.id
  let localAssistantId = `local-pending-${message.id}`
  generationState.value = 'starting'
  activeAbort.value = new AbortController()
  try {
    const targetConversationId = await ensureConversation()
    const materialIds = message.attachments?.map((attachment) => attachment.id)
      ?? message.runtimeMaterialContext?.map((context) => context.id)
      ?? []
    let lastSeq = 0
    let fallbackToLegacy = false
    try {
      await expertApi.streamMessage(targetConversationId, message.text, message.clientMessageId, materialIds, {
        onRun: (runId, userMessage) => {
          activeRunId.value = runId
          generationState.value = 'streaming'
          replaceOptimisticMessage(message.id, userMessage)
          persistedUserId = userMessage.id
          beginTimelineRun(localAssistantId, runId, materialIds.length)
        },
        onDelta: (runId, seq, text) => {
          if (runId !== activeRunId.value || seq <= lastSeq) return
          lastSeq = seq
          clearSlowWaiting(runId)
          clearSignificantWait(runId)
          if (!localAssistantId) {
            localAssistantId = `local-stream-${runId}`
            appendServerAssistantMessage(targetConversationId, { id: localAssistantId, role: 'assistant', text: '', createdAt: new Date().toISOString(), deliveryState: 'sending' })
            beginTimelineRun(localAssistantId, runId, materialIds.length)
          }
          updateMessageText(localAssistantId, text)
          if (targetConversationId === conversationId.value) scheduleActiveResponseFollow()
        },
        onActivity: (activity) => {
          if (activity.runId === activeRunId.value && localAssistantId) { applyTimelineActivity(localAssistantId, activity); scheduleActiveResponseFollow() }
        },
        onReasoningSummary: (summary) => {
          if (summary.runId === activeRunId.value && localAssistantId) { appendTimelineSummary(localAssistantId, summary); scheduleActiveResponseFollow() }
        },
        onDone: (assistantMessage) => {
          generationState.value = 'completed'
          if (assistantMessage && localAssistantId) {
            replaceOptimisticMessage(localAssistantId, assistantMessage)
            moveTimelineRun(localAssistantId, assistantMessage.id)
            finishTimelineRun(assistantMessage.id, activeRunId.value ?? '', 'completed')
            localAssistantId = assistantMessage.id
          } else if (assistantMessage) {
            appendServerAssistantMessage(targetConversationId, assistantMessage)
          } else if (localAssistantId) {
            finishTimelineRun(localAssistantId, activeRunId.value ?? '', 'completed')
            removeMessage(localAssistantId)
            discardTimelineRun(localAssistantId)
          }
          if (assistantMessage) delete continuableAssistantIds.value[assistantMessage.id]
        },
        onCancelled: (assistantMessage) => {
          generationState.value = 'stopped'
          if (assistantMessage && localAssistantId) {
            replaceOptimisticMessage(localAssistantId, assistantMessage)
            moveTimelineRun(localAssistantId, assistantMessage.id)
            finishTimelineRun(assistantMessage.id, activeRunId.value ?? '', 'cancelled')
            localAssistantId = assistantMessage.id
          } else if (assistantMessage) {
            appendServerAssistantMessage(targetConversationId, assistantMessage)
          } else if (localAssistantId) {
            finishTimelineRun(localAssistantId, activeRunId.value ?? '', 'cancelled')
            removeMessage(localAssistantId)
            discardTimelineRun(localAssistantId)
          }
          if (assistantMessage) continuableAssistantIds.value = { ...continuableAssistantIds.value, [assistantMessage.id]: true }
        },
        onError: (mapped, assistantMessage) => {
          const useLegacyFallback = shouldUseLegacyExpertChatFallback(mapped.code, assistantMessage)
          generationState.value = useLegacyFallback ? 'starting' : 'interrupted'
          if (assistantMessage && localAssistantId) {
            replaceOptimisticMessage(localAssistantId, assistantMessage)
            moveTimelineRun(localAssistantId, assistantMessage.id)
            finishTimelineRun(assistantMessage.id, activeRunId.value ?? '', 'interrupted')
            localAssistantId = assistantMessage.id
          } else if (assistantMessage) {
            appendServerAssistantMessage(targetConversationId, assistantMessage)
          } else if (localAssistantId && !useLegacyFallback) {
            finishTimelineRun(localAssistantId, activeRunId.value ?? '', 'interrupted')
            removeMessage(localAssistantId)
            discardTimelineRun(localAssistantId)
            if (isExpertMaterialContextError(mapped.code)) restoreMessageMaterialContext(message)
          }
          if (assistantMessage) {
            updateMessageDelivery(assistantMessage.id, 'error', mapped.message)
            updateMessageDiagnostic(assistantMessage.id, mapped.diagnostic)
            continuableAssistantIds.value = { ...continuableAssistantIds.value, [assistantMessage.id]: true }
          } else if (!useLegacyFallback) {
            updateMessageDelivery(persistedUserId, 'error', mapped.message)
            updateMessageDiagnostic(persistedUserId, mapped.diagnostic)
          }
          fallbackToLegacy = useLegacyFallback
        },
      }, activeAbort.value.signal)
      if (fallbackToLegacy) {
        const reply = await expertApi.sendMessage(targetConversationId, message.text, message.clientMessageId, materialIds)
        replaceSavedMessage(persistedUserId, reply.userMessage)
        replaceOptimisticMessage(localAssistantId, reply.assistantMessage)
        discardTimelineRun(localAssistantId)
        generationState.value = 'completed'
      }
    } catch (error) {
      const mapped = mapExpertApiError(error)
      if (mapped.code === 'streaming_not_supported') {
        const reply = await expertApi.sendMessage(targetConversationId, message.text, message.clientMessageId, materialIds)
        replaceOptimisticMessage(message.id, reply.userMessage)
        replaceOptimisticMessage(localAssistantId, reply.assistantMessage)
        discardTimelineRun(localAssistantId)
        generationState.value = 'completed'
      } else if ((error as DOMException).name !== 'AbortError') {
        throw error
      } else {
        removeMessage(localAssistantId)
        discardTimelineRun(localAssistantId)
        updateMessageDelivery(persistedUserId, 'error', 'Запрос остановлен.')
      }
    } finally {
      if (findMessage(localAssistantId)?.deliveryState === 'sending' && !findMessage(localAssistantId)?.text) {
        removeMessage(localAssistantId)
        discardTimelineRun(localAssistantId)
      }
      resetTransientGenerationState()
    }
  } catch (error) {
    const mapped = mapExpertApiError(error)
    if (isExpertMaterialContextError(mapped.code)) restoreMessageMaterialContext(message)
    if (localAssistantId && findMessage(localAssistantId)?.text) {
      updateMessageDelivery(localAssistantId, 'error', mapped.message)
    } else if (localAssistantId) {
      removeMessage(localAssistantId)
      discardTimelineRun(localAssistantId)
    }
    if (!localAssistantId || !findMessage(localAssistantId)?.text) updateMessageDelivery(persistedUserId, 'error', mapped.message)
    resetTransientGenerationState()
  }
}

function stopStream() {
  const runId = activeRunId.value
  const targetConversationId = conversationId.value
  if (generationState.value === 'stopping') return
  if (!runId || !targetConversationId) {
    activeAbort.value?.abort()
    generationState.value = 'stopping'
    return
  }
  generationState.value = 'stopping'
  void expertApi.cancelStream(targetConversationId, runId).catch((error) => {
    generationState.value = 'streaming'
    notify(mapExpertApiError(error).message)
  })
}

async function continueMessage(assistantId: string) {
  if (!conversationId.value || streamActive.value) return
  const assistant = findMessage(assistantId)
  if (!assistant) return
  updateMessageDelivery(assistantId, 'sending')
  generationState.value = 'starting'
  activeAbort.value = new AbortController()
  let lastSeq = 0
  let continuationRunId = ''
  try {
    await expertApi.streamMessage(conversationId.value, '', crypto.randomUUID(), [], {
      onRun: (runId) => {
        continuationRunId = runId
        activeRunId.value = runId
        generationState.value = 'streaming'
        beginTimelineRun(assistantId, runId)
      },
      onDelta: (runId, seq, text) => {
        if (runId !== activeRunId.value || seq <= lastSeq) return
        lastSeq = seq
        clearSlowWaiting(runId)
        clearSignificantWait(runId)
        updateMessageText(assistantId, text)
        scheduleActiveResponseFollow()
      },
      onActivity: (activity) => { if (activity.runId === continuationRunId) { applyTimelineActivity(assistantId, activity); scheduleActiveResponseFollow() } },
      onReasoningSummary: (summary) => { if (summary.runId === continuationRunId) { appendTimelineSummary(assistantId, summary); scheduleActiveResponseFollow() } },
      onDone: (saved) => {
        generationState.value = 'completed'
        finishTimelineRun(assistantId, continuationRunId, 'completed')
        if (saved) replaceSavedMessage(assistantId, saved)
        else updateMessageDelivery(assistantId, 'sent')
        const { [assistantId]: removed, ...rest } = continuableAssistantIds.value
        void removed
        continuableAssistantIds.value = rest
      },
      onCancelled: (saved) => {
        generationState.value = 'stopped'
        finishTimelineRun(assistantId, continuationRunId, 'cancelled')
        if (saved) replaceSavedMessage(assistantId, saved)
        else updateMessageDelivery(assistantId, 'sent')
      },
      onError: (mapped, saved) => {
        generationState.value = 'interrupted'
        finishTimelineRun(assistantId, continuationRunId, 'interrupted')
        if (saved) replaceSavedMessage(assistantId, saved)
        updateMessageDelivery(assistantId, 'error', mapped.message)
        updateMessageDiagnostic(assistantId, mapped.diagnostic)
      },
    }, activeAbort.value.signal, assistantId)
  } catch (error) {
    if ((error as DOMException).name !== 'AbortError') updateMessageDelivery(assistantId, 'error', mapExpertApiError(error).message)
    else updateMessageDelivery(assistantId, 'sent')
  } finally {
    resetTransientGenerationState()
  }
}

function retryMessage(messageId: string) {
  const message = findMessage(messageId)
  if (!message || message.role !== 'user' || message.deliveryState === 'sending') return
  updateMessageDelivery(message.id, 'sending')
  void persistMessage(message)
}

function sendMessage(text: string, accepted: () => void = () => undefined) {
  const normalizedText = normalizeExpertChatDraft(text)
  if (!normalizedText || streamActive.value || (props.projectMode === 'real' && composerSendBlockedReason.value)) return
  followActiveResponse.value = nextExpertChatFollowState(followActiveResponse.value, 'own-message')
  userScrollIntentUntil = 0

  if (props.projectMode === 'demo') {
    const userMessage = { ...createOptimisticUserMessage(normalizedText), deliveryState: 'sent' as const }
    const reply: ExpertMessage = {
      id: `local-demo-${Date.now()}`,
      role: 'assistant',
      text: 'Это демонстрационный ответ. Для рабочего проекта ответ формируется AI.',
      createdAt: new Date().toISOString(),
      deliveryState: 'sent',
    }
    appendMessages([userMessage, reply])
    accepted()
    void scrollToLatest('smooth')
    return
  }

  const optimisticMessage: ExpertMessage = {
    ...createOptimisticUserMessage(normalizedText),
    // Keep the pending selection visible until the server returns persisted attachments.
    runtimeMaterialContext: snapshotExpertMessageMaterialContext(composerMaterialContexts.value),
  }
  appendMessages([optimisticMessage, {
    id: `local-pending-${optimisticMessage.id}`,
    role: 'assistant',
    text: '',
    createdAt: new Date().toISOString(),
    deliveryState: 'sending',
  }])
  accepted()
  composerMaterialContexts.value = []
  void scrollToLatest('smooth')
  void persistMessage(optimisticMessage)
}

function attachComposerFiles(files: File[]) {
  if (props.projectMode !== 'real' || !files.length) return

  transfers.queueUploads(props.project.id, files, (material) => {
    if (!props.project.materials.some((item) => item.id === material.id)) {
      props.project.materials.unshift(material)
      props.project.counts && (props.project.counts.materials = props.project.materials.length)
    }
    composerMaterialContexts.value = addExpertMessageMaterialContext(composerMaterialContexts.value, material)
    void transfers.loadImagePreview(material)
  })
}

async function openLibrary() {
  if (props.projectMode !== 'real') return
  libraryOpen.value = true
  libraryLoading.value = true
  try {
    props.project.materials = await expertApi.listMaterials(props.project.id)
  } catch (error) {
    snackbarText.value = mapExpertApiError(error).message
    snackbarOpen.value = true
    libraryOpen.value = false
  } finally {
    libraryLoading.value = false
  }
}

function confirmLibrarySelection(ids: string[]) {
  const byId = new Map(props.project.materials.map((material) => [material.id, material]))
  composerMaterialContexts.value = ids.flatMap((id) => {
    const material = byId.get(id)
    if (!material) return []
    if (material.kind === 'image') void transfers.loadImagePreview(material)
    return [addExpertMessageMaterialContext([], material)[0]!]
  })
  libraryOpen.value = false
}

async function openMessageMaterial(id: string) {
  let material = props.project.materials.find((item) => item.id === id)
  if (!material) {
    try {
      props.project.materials = await expertApi.listMaterials(props.project.id)
      material = props.project.materials.find((item) => item.id === id)
    } catch (error) {
      snackbarText.value = mapExpertApiError(error).message
      snackbarOpen.value = true
      return
    }
  }
  if (!material) return
  selectedMaterial.value = material
  materialDrawerOpen.value = true
  if (material.kind === 'image') void transfers.loadImagePreview(material)
}

function handleMaterialAction(action: string) {
  if (!selectedMaterial.value) return
  if (action === 'download') void transfers.downloadMaterial(selectedMaterial.value)
  if (action === 'delete') {
    materialDrawerOpen.value = false
    snackbarText.value = 'Удалите материал в библиотеке проекта.'
    snackbarOpen.value = true
  }
}

function retryComposerUpload(id: string) {
  if (props.projectMode === 'real') transfers.retryUpload(props.project.id, id)
}

function removeFailedComposerUpload(id: string) {
  transfers.removeUpload(id)
}

function removeComposerMaterialContext(id: string) {
  composerMaterialContexts.value = composerMaterialContexts.value.filter((context) => context.id !== id)
}

function isMessageAreaNearBottom(): boolean {
  const element = messageArea.value
  return !element || isNearExpertChatBottom(element)
}

function updateScrollPosition() {
  if (performance.now() > userScrollIntentUntil) return
  const nearBottom = isMessageAreaNearBottom()
  followActiveResponse.value = nextExpertChatFollowState(followActiveResponse.value, nearBottom ? 'user-scroll-bottom' : 'user-scroll-up')
  showScrollToBottom.value = !followActiveResponse.value
  if (!followActiveResponse.value && followFrame) { cancelAnimationFrame(followFrame); followFrame = 0 }
}

function markUserScrollIntent() {
  userScrollIntentUntil = performance.now() + 1000
}

function markPointerScrollIntent(event: PointerEvent) {
  const element = messageArea.value
  if (element && event.target === element && event.offsetX >= element.clientWidth - 16) markUserScrollIntent()
}

function markKeyboardScrollIntent(event: KeyboardEvent) {
  if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].includes(event.key)) markUserScrollIntent()
}

function scheduleActiveResponseFollow() {
  if (!nextExpertChatFollowState(followActiveResponse.value, 'stream-growth') || followFrame) return
  followFrame = requestAnimationFrame(async () => {
    followFrame = 0
    await nextTick()
    if (!followActiveResponse.value) return
    const element = messageArea.value
    if (element) element.scrollTop = element.scrollHeight
  })
}

async function scrollToLatest(behavior: ScrollBehavior) {
  followActiveResponse.value = nextExpertChatFollowState(followActiveResponse.value, 'new-messages-click')
  userScrollIntentUntil = 0
  await nextTick()
  const element = messageArea.value
  if (!element) return
  element.scrollTo({ top: element.scrollHeight, behavior })
  showScrollToBottom.value = false
}

function notify(action: string) {
  snackbarText.value = action + ': функция доступна только в демонстрационном режиме.'
  snackbarOpen.value = true
}
function handleAttachment(action: string) {
  if (action === 'library') { void openLibrary(); return }
  notify(action === 'materials' ? 'Контекст материалов' : 'Добавление вложения')
}
function removeContext(id: string) {
  contextChips.value = removeChatContext(contextChips.value, id)
}
function selectWholeProject() {
  contextChips.value = selectWholeProjectChatContext()
}

watch(() => props.project.id, () => { void requestConversations() }, { immediate: true })
watch(conversationId, (id) => {
  composerMaterialContexts.value = []
  transfers.clearUploads()
  followActiveResponse.value = true
  showScrollToBottom.value = false
  const storageKey = lastConversationStorageKey.value
  if (id && storageKey) writeExpertLastConversation(window.localStorage, storageKey, id)
  void loadMessages(id)
})
watch(messageArea, (element, _, onCleanup) => {
  if (!element) return
  const observer = new MutationObserver(() => { if (streamActive.value) scheduleActiveResponseFollow() })
  observer.observe(element, { childList: true, characterData: true, subtree: true })
  onCleanup(() => observer.disconnect())
})
watch(() => props.project.id, () => {
  composerMaterialContexts.value = []
  timelineByAssistant.value = {}
  clearAllSlowWaiting()
  transfers.clearUploads()
  transfers.syncImagePreviews(props.project.materials)
})
onBeforeUnmount(() => { activeAbort.value?.abort(); if (followFrame) cancelAnimationFrame(followFrame); clearAllSlowWaiting(); timelineByAssistant.value = {}; transfers.dispose() })
</script>

<style scoped>
.expert-chat { display: flex; height: 100%; min-height: 0; overflow: hidden; background: rgb(var(--v-theme-background)); }
.expert-chat__main { display: flex; flex: 1; min-width: 0; min-height: 0; flex-direction: column; }
.expert-chat__toolbar { display: flex; align-items: center; justify-content: space-between; min-height: 54px; padding: 7px 14px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .55); background: rgb(var(--v-theme-surface)); }
.expert-chat__conversation { font-weight: 800; text-transform: none; }
.expert-chat__toolbar-actions { display: flex; gap: 4px; }
.expert-chat__messages-wrap { position: relative; display: flex; flex: 1; min-height: 0; }
.expert-chat__messages { display: flex; flex: 1; min-height: 0; flex-direction: column; gap: 24px; overflow-y: auto; padding: 28px max(16px, calc((100% - 960px) / 2)); }
.expert-chat__new-messages { position: absolute; right: max(16px, calc((100% - 960px) / 2)); bottom: 18px; z-index: 1; border: 1px solid rgba(var(--v-theme-outline), .28); box-shadow: var(--ds-shadow-soft); text-transform: none; }
.expert-chat__empty { display: grid; align-content: center; justify-items: center; min-height: 100%; padding: 24px; text-align: center; }
.expert-chat__empty-icon { display: grid; place-items: center; width: 54px; height: 54px; margin-bottom: 16px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); }
.expert-chat__empty h1 { margin: 0; font-size: clamp(1.35rem, 3vw, 1.85rem); letter-spacing: -.025em; }
.expert-chat__empty p { max-width: 540px; margin: 8px 0 24px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .82rem; }
.expert-chat__quick-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 260px)); gap: 9px; }
.expert-chat__quick-actions button { display: flex; align-items: center; gap: 9px; padding: 12px 13px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .84); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; font-size: .76rem; }
.expert-chat__quick-actions button:hover { border-color: rgba(var(--v-theme-primary), .52); background: rgba(var(--v-theme-primary), .045); }
@media (max-width: 700px) { .expert-chat__messages { gap: 18px; padding: 18px 12px; } .expert-chat__new-messages { right: 12px; bottom: 12px; } .expert-chat__quick-actions { grid-template-columns: 1fr; width: 100%; } }
</style>
