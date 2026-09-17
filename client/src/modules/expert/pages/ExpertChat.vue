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
        <div ref="messageArea" class="expert-chat__messages" @scroll.passive="updateScrollPosition">
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
            :allow-continue="Boolean(continuableAssistantIds[message.id])"
            :timeline-runs="timelineRunsFor(message.id)"
            @action="notify"
            @open-source="contextOpen = true"
            @retry="retryMessage"
            @continue="continueMessage"
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

    <v-snackbar v-model="snackbarOpen" :timeout="2600">{{ snackbarText }}</v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import ExpertChatComposer from '../components/chat/ExpertChatComposer.vue'
import ExpertChatMessage from '../components/chat/ExpertChatMessage.vue'
import ExpertContextPanel from '../components/chat/ExpertContextPanel.vue'
import { createWholeProjectContext, removeChatContext, selectWholeProjectChatContext, type ExpertChatContextChip } from '../chatContext'
import { addExpertMessageMaterialContext, getExpertChatAttachmentSendBlockReason, mergeExpertMessageMaterialContexts, snapshotExpertMessageMaterialContext } from '../chatAttachments'
import { normalizeExpertChatDraft } from '../chatComposer'
import { shouldUseLegacyExpertChatFallback } from '../chatStreamingFallback'
import { appendUniqueExpertMessage, createOptimisticUserMessage, replaceOptimisticExpertMessage, setExpertMessageDeliveryState } from '../chatMessageState'
import { isNearExpertChatBottom, shouldFollowNewExpertMessage } from '../chatScroll'
import {
  addExpertTimelineRun,
  appendExpertReasoningSummary,
  applyExpertTimelineActivity,
  finishExpertTimelineRun,
  moveExpertTimelineRuns,
  removeExpertTimelineRuns,
  type ExpertReasoningSummaryEvent,
  type ExpertRunActivity,
  type ExpertTimelineRun,
} from '../chatTimeline'
import { useExpertMaterialTransfers } from '../composables/useExpertMaterialTransfers'
import { expertApi, isExpertMaterialContextError, mapExpertApiError } from '../api'
import type { ExpertConversation, ExpertMessage, ExpertMessageMaterialContext, ExpertProject, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const { mdAndDown } = useDisplay()
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
const transfers = useExpertMaterialTransfers()
const composerMaterialContexts = ref<ExpertMessageMaterialContext[]>([])
const composerUploadItems = computed(() => transfers.uploads.value)
const composerImagePreviews = computed(() => transfers.imagePreviews.value)
const composerSendBlockedReason = computed(() => getExpertChatAttachmentSendBlockReason(
  composerUploadItems.value,
  composerMaterialContexts.value,
))
const generationState = ref<'idle' | 'starting' | 'streaming' | 'stopping' | 'completed' | 'stopped' | 'interrupted' | 'error'>('idle')
const activeRunId = ref<string | null>(null)
const activeAbort = ref<AbortController | null>(null)
const continuationSnapshots = new Map<string, { content: string; materialIds: string[] }>()
const continuableAssistantIds = ref<Record<string, true>>({})
const timelineByAssistant = ref<Record<string, ExpertTimelineRun[]>>({})
const streamActive = computed(() => ['starting', 'streaming', 'stopping'].includes(generationState.value))
let conversationsSequence = 0
let messagesSequence = 0
let conversationCreationPromise: Promise<string> | null = null
let conversationsLoadPromise: Promise<void> | null = null

function resetTransientGenerationState() {
  const currentState: string = generationState.value
  if (['starting', 'streaming', 'stopping'].includes(currentState)) generationState.value = 'idle'
}

const conversation = computed(() => props.project.conversations.find((item) => item.id === conversationId.value))
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

function replaceOptimisticMessage(optimisticId: string, savedMessage: ExpertMessage) {
  const optimistic = findMessage(optimisticId)
  const savedWithRuntime: ExpertMessage = {
    ...savedMessage,
    ...(optimistic?.clientMessageId ? { clientMessageId: optimistic.clientMessageId } : {}),
    ...(optimistic?.runtimeMaterialContext ? { runtimeMaterialContext: optimistic.runtimeMaterialContext } : {}),
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

function beginTimelineRun(assistantId: string, runId: string) {
  timelineByAssistant.value = {
    ...timelineByAssistant.value,
    [assistantId]: addExpertTimelineRun(timelineRunsFor(assistantId), runId),
  }
}

function applyTimelineActivity(assistantId: string, activity: ExpertRunActivity) {
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
    conversationId.value = loaded[0]?.id ?? ''
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
    messagesByConversation.value = { ...messagesByConversation.value, [id]: loaded }
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

  try {
    const targetConversationId = await ensureConversation()
    const materialIds = message.runtimeMaterialContext?.map((context) => context.id) ?? []
    generationState.value = 'starting'
    activeAbort.value = new AbortController()
    let localAssistantId = ''
    let persistedUserId = message.id
    let lastSeq = 0
    let fallbackToLegacy = false
    try {
      await expertApi.streamMessage(targetConversationId, message.text, message.clientMessageId, materialIds, {
        onRun: (runId, userMessage) => {
          activeRunId.value = runId
          generationState.value = 'streaming'
          replaceOptimisticMessage(message.id, userMessage)
          persistedUserId = userMessage.id
          localAssistantId = `local-stream-${runId}`
          appendServerAssistantMessage(targetConversationId, { id: localAssistantId, role: 'assistant', text: '', createdAt: new Date().toISOString() })
          beginTimelineRun(localAssistantId, runId)
        },
        onDelta: (runId, seq, text) => {
          if (runId !== activeRunId.value || seq <= lastSeq) return
          lastSeq = seq
          const wasNearBottom = targetConversationId === conversationId.value && isMessageAreaNearBottom()
          if (!localAssistantId) {
            localAssistantId = `local-stream-${runId}`
            appendServerAssistantMessage(targetConversationId, { id: localAssistantId, role: 'assistant', text: '', createdAt: new Date().toISOString() })
            beginTimelineRun(localAssistantId, runId)
          }
          updateMessageText(localAssistantId, text)
          if (targetConversationId === conversationId.value) void handleMessageAdded(wasNearBottom, false)
        },
        onActivity: (activity) => {
          if (activity.runId === activeRunId.value && localAssistantId) applyTimelineActivity(localAssistantId, activity)
        },
        onReasoningSummary: (summary) => {
          if (summary.runId === activeRunId.value && localAssistantId) appendTimelineSummary(localAssistantId, summary)
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
          if (assistantMessage) { continuationSnapshots.delete(assistantMessage.id); delete continuableAssistantIds.value[assistantMessage.id] }
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
          if (assistantMessage) { continuationSnapshots.set(assistantMessage.id, { content: message.text, materialIds }); continuableAssistantIds.value = { ...continuableAssistantIds.value, [assistantMessage.id]: true } }
        },
        onError: (mapped, assistantMessage) => {
          generationState.value = 'interrupted'
          const useLegacyFallback = shouldUseLegacyExpertChatFallback(mapped.code, assistantMessage)
          if (assistantMessage && localAssistantId) {
            replaceOptimisticMessage(localAssistantId, assistantMessage)
            moveTimelineRun(localAssistantId, assistantMessage.id)
            finishTimelineRun(assistantMessage.id, activeRunId.value ?? '', 'interrupted')
            localAssistantId = assistantMessage.id
          } else if (assistantMessage) {
            appendServerAssistantMessage(targetConversationId, assistantMessage)
          } else if (localAssistantId) {
            finishTimelineRun(localAssistantId, activeRunId.value ?? '', 'interrupted')
            removeMessage(localAssistantId)
            discardTimelineRun(localAssistantId)
            if (isExpertMaterialContextError(mapped.code)) restoreMessageMaterialContext(message)
            if (!useLegacyFallback) updateMessageDelivery(persistedUserId, 'error', mapped.message)
          }
          if (assistantMessage) { continuationSnapshots.set(assistantMessage.id, { content: message.text, materialIds }); continuableAssistantIds.value = { ...continuableAssistantIds.value, [assistantMessage.id]: true } }
          fallbackToLegacy = useLegacyFallback
          if (!useLegacyFallback) errorMessage.value = mapped.message
        },
      }, activeAbort.value.signal)
      if (fallbackToLegacy) {
        const reply = await expertApi.sendMessage(targetConversationId, message.text, message.clientMessageId, materialIds)
        replaceSavedMessage(persistedUserId, reply.userMessage)
        appendServerAssistantMessage(targetConversationId, reply.assistantMessage)
        generationState.value = 'completed'
      }
    } catch (error) {
      const mapped = mapExpertApiError(error)
      if (mapped.code === 'streaming_not_supported') {
        const reply = await expertApi.sendMessage(targetConversationId, message.text, message.clientMessageId, materialIds)
        replaceOptimisticMessage(message.id, reply.userMessage)
        appendServerAssistantMessage(targetConversationId, reply.assistantMessage)
      } else if ((error as DOMException).name !== 'AbortError') {
        throw error
      }
    } finally {
      activeAbort.value = null
      activeRunId.value = null
      resetTransientGenerationState()
    }
  } catch (error) {
    const mapped = mapExpertApiError(error)
    if (isExpertMaterialContextError(mapped.code)) restoreMessageMaterialContext(message)
    updateMessageDelivery(message.id, 'error', mapped.message)
  }
}

function stopStream() {
  const runId = activeRunId.value
  const targetConversationId = conversationId.value
  if (!runId || !targetConversationId || generationState.value === 'stopping') return
  generationState.value = 'stopping'
  void expertApi.cancelStream(targetConversationId, runId).catch((error) => {
    generationState.value = 'error'
    errorMessage.value = mapExpertApiError(error).message
  })
}

async function continueMessage(assistantId: string) {
  const snapshot = continuationSnapshots.get(assistantId)
  if (!snapshot || !conversationId.value || streamActive.value) {
    errorMessage.value = 'Продолжение доступно только в текущем сеансе с исходным контекстом.'
    return
  }
  const assistant = findMessage(assistantId)
  if (!assistant) return
  generationState.value = 'starting'
  activeAbort.value = new AbortController()
  let lastSeq = 0
  let continuationRunId = ''
  try {
    await expertApi.streamMessage(conversationId.value, snapshot.content, crypto.randomUUID(), snapshot.materialIds, {
      onRun: (runId) => {
        continuationRunId = runId
        activeRunId.value = runId
        generationState.value = 'streaming'
        beginTimelineRun(assistantId, runId)
      },
      onDelta: (runId, seq, text) => {
        if (runId !== activeRunId.value || seq <= lastSeq) return
        lastSeq = seq
        const nearBottom = isMessageAreaNearBottom()
        updateMessageText(assistantId, text)
        void handleMessageAdded(nearBottom, false)
      },
      onActivity: (activity) => { if (activity.runId === continuationRunId) applyTimelineActivity(assistantId, activity) },
      onReasoningSummary: (summary) => { if (summary.runId === continuationRunId) appendTimelineSummary(assistantId, summary) },
      onDone: (saved) => {
        generationState.value = 'completed'
        finishTimelineRun(assistantId, continuationRunId, 'completed')
        if (saved) replaceSavedMessage(assistantId, saved)
        continuationSnapshots.delete(assistantId)
        const { [assistantId]: removed, ...rest } = continuableAssistantIds.value
        void removed
        continuableAssistantIds.value = rest
      },
      onCancelled: (saved) => {
        generationState.value = 'stopped'
        finishTimelineRun(assistantId, continuationRunId, 'cancelled')
        if (saved) replaceSavedMessage(assistantId, saved)
      },
      onError: (mapped, saved) => {
        generationState.value = 'interrupted'
        finishTimelineRun(assistantId, continuationRunId, 'interrupted')
        if (saved) replaceSavedMessage(assistantId, saved)
        errorMessage.value = mapped.message
      },
    }, activeAbort.value.signal, assistantId)
  } catch (error) {
    if ((error as DOMException).name !== 'AbortError') errorMessage.value = mapExpertApiError(error).message
  } finally {
    activeAbort.value = null
    activeRunId.value = null
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
  if (!normalizedText || (props.projectMode === 'real' && composerSendBlockedReason.value)) return
  const wasNearBottom = isMessageAreaNearBottom()

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
    void handleMessageAdded(wasNearBottom, true)
    return
  }

  const optimisticMessage: ExpertMessage = {
    ...createOptimisticUserMessage(normalizedText),
    // Runtime-only material snapshot is reused unchanged by retry and never persisted in messages.
    runtimeMaterialContext: snapshotExpertMessageMaterialContext(composerMaterialContexts.value),
  }
  appendMessages([optimisticMessage])
  accepted()
  composerMaterialContexts.value = []
  void handleMessageAdded(wasNearBottom, true)
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
  showScrollToBottom.value = !isMessageAreaNearBottom()
}

async function scrollToLatest(behavior: ScrollBehavior) {
  await nextTick()
  const element = messageArea.value
  if (!element) return
  element.scrollTo({ top: element.scrollHeight, behavior })
  showScrollToBottom.value = false
}

async function handleMessageAdded(wasNearBottom: boolean, isOwnMessage: boolean) {
  await nextTick()
  if (shouldFollowNewExpertMessage(wasNearBottom, isOwnMessage)) {
    await scrollToLatest('smooth')
    return
  }
  showScrollToBottom.value = true
}

function notify(action: string) {
  snackbarText.value = action + ': функция доступна только в демонстрационном режиме.'
  snackbarOpen.value = true
}
function handleAttachment(action: string) {
  notify(action === 'materials' ? 'Контекст материалов' : 'Добавление вложения')
}
function removeContext(id: string) {
  contextChips.value = removeChatContext(contextChips.value, id)
}
function selectWholeProject() {
  contextChips.value = selectWholeProjectChatContext()
}

watch(() => props.project.id, () => { void requestConversations() }, { immediate: true })
watch(conversationId, (id) => { void loadMessages(id) })
watch(() => props.project.id, () => {
  composerMaterialContexts.value = []
  timelineByAssistant.value = {}
  transfers.clearUploads()
  transfers.syncImagePreviews(props.project.materials)
})
onBeforeUnmount(() => { activeAbort.value?.abort(); timelineByAssistant.value = {}; transfers.dispose() })
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
