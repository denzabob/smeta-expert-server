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
        {{ errorMessage }}
      </v-alert>

      <div ref="messageArea" class="expert-chat__messages">
        <div v-if="!loading && !messages.length" class="expert-chat__empty">
          <div class="expert-chat__empty-icon"><v-icon :icon="projectMode === 'demo' ? 'mdi-prism' : 'mdi-message-text-outline'" size="30" /></div>
          <h1>{{ projectMode === 'demo' ? 'Чем помочь в этом исследовании?' : 'Сообщений пока нет' }}</h1>
          <p>{{ projectMode === 'demo' ? 'Prism AI работает с демонстрационным контекстом проекта.' : 'Создайте чат и добавьте первое сообщение. AI-ответы подключаются на следующем этапе.' }}</p>
          <div v-if="projectMode === 'demo'" class="expert-chat__quick-actions">
            <button v-for="action in project.quickActions" :key="action" type="button" @click="sendMessage(action)">
              <v-icon icon="mdi-arrow-up-right" size="17" /><span>{{ action }}</span>
            </button>
          </div>
          <v-btn v-else-if="!conversation" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openNewConversation">Создать чат</v-btn>
        </div>
        <ExpertChatMessage
          v-for="message in messages"
          v-else
          :key="message.id"
          :message="message"
          @action="notify"
          @open-source="contextOpen = true"
        />
      </div>

      <ExpertChatComposer
        :context-chips="projectMode === 'demo' ? contextChips : []"
        :busy="sending || loading"
        :persistence-only="projectMode === 'real'"
        @send="sendMessage"
        @attachment="handleAttachment"
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
import { computed, nextTick, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import ExpertChatComposer from '../components/chat/ExpertChatComposer.vue'
import ExpertChatMessage from '../components/chat/ExpertChatMessage.vue'
import ExpertContextPanel from '../components/chat/ExpertContextPanel.vue'
import { createWholeProjectContext, removeChatContext, selectWholeProjectChatContext, type ExpertChatContextChip } from '../chatContext'
import { expertApi, mapExpertApiError } from '../api'
import type { ExpertMessage, ExpertProject, ExpertProjectMode } from '../types'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const { mdAndDown } = useDisplay()
const conversationId = ref('')
const messagesByConversation = ref<Record<string, ExpertMessage[]>>({})
const contextOpen = ref(props.projectMode === 'demo' && !mdAndDown.value)
const contextChips = ref<ExpertChatContextChip[]>(createWholeProjectContext())
const loading = ref(false)
const sending = ref(false)
const errorMessage = ref('')
const newConversationOpen = ref(false)
const newConversationTitle = ref('Общий анализ')
const creatingConversation = ref(false)
const conversationError = ref('')
const snackbarOpen = ref(false)
const snackbarText = ref('')
const messageArea = ref<HTMLElement | null>(null)
let conversationsSequence = 0
let messagesSequence = 0

const conversation = computed(() => props.project.conversations.find((item) => item.id === conversationId.value))
const messages = computed(() => messagesByConversation.value[conversationId.value] ?? conversation.value?.messages ?? [])

async function loadConversations() {
  const sequence = ++conversationsSequence
  const targetProject = props.project
  errorMessage.value = ''
  if (props.projectMode === 'demo') {
    conversationId.value = props.project.conversations[0]?.id ?? ''
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

async function loadMessages(id: string) {
  if (props.projectMode === 'demo' || !id || Object.prototype.hasOwnProperty.call(messagesByConversation.value, id)) return
  const sequence = ++messagesSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const loaded = await expertApi.listMessages(id)
    if (sequence !== messagesSequence) return
    messagesByConversation.value = { ...messagesByConversation.value, [id]: loaded }
  } catch (error) {
    if (sequence === messagesSequence) errorMessage.value = mapExpertApiError(error).message
  } finally {
    if (sequence === messagesSequence) loading.value = false
  }
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
    props.project.conversations.push(created)
    props.project.counts && (props.project.counts.conversations = props.project.conversations.length)
    messagesByConversation.value = { ...messagesByConversation.value, [created.id]: [] }
    conversationId.value = created.id
    newConversationOpen.value = false
  } catch (error) {
    conversationError.value = mapExpertApiError(error).message
  } finally {
    creatingConversation.value = false
  }
}

async function sendMessage(text: string, complete: (saved: boolean) => void = () => undefined) {
  let savedSuccessfully = false
  if (props.projectMode === 'demo') {
    const id = conversationId.value
    const current = messages.value
    const stamp = Date.now()
    const userMessage: ExpertMessage = { id: 'local-' + stamp, role: 'user', text, createdAt: 'сейчас' }
    const reply: ExpertMessage = { id: 'local-ai-' + stamp, role: 'assistant', text: 'Это демонстрационный ответ. Реальный AI Gateway не входит в текущий этап.', createdAt: 'сейчас' }
    messagesByConversation.value = { ...messagesByConversation.value, [id]: [...current, userMessage, reply] }
    savedSuccessfully = true
  } else {
    sending.value = true
    errorMessage.value = ''
    try {
      let targetConversationId = conversationId.value
      if (!targetConversationId) {
        const created = await expertApi.createConversation(props.project.id, 'Общий анализ')
        props.project.conversations.push(created)
        props.project.counts && (props.project.counts.conversations = props.project.conversations.length)
        targetConversationId = created.id
        messagesByConversation.value = { ...messagesByConversation.value, [created.id]: [] }
        conversationId.value = created.id
      }
      const saved = await expertApi.sendMessage(targetConversationId, text)
      messagesByConversation.value = {
        ...messagesByConversation.value,
        [targetConversationId]: [...(messagesByConversation.value[targetConversationId] ?? []), saved],
      }
      savedSuccessfully = true
    } catch (error) {
      errorMessage.value = mapExpertApiError(error).message
    } finally {
      sending.value = false
    }
  }
  complete(savedSuccessfully)
  await nextTick()
  messageArea.value?.scrollTo({ top: messageArea.value.scrollHeight, behavior: 'smooth' })
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

watch(() => props.project.id, loadConversations, { immediate: true })
watch(conversationId, loadMessages)
</script>

<style scoped>
.expert-chat { display: flex; height: 100%; min-height: 0; overflow: hidden; background: rgb(var(--v-theme-background)); }
.expert-chat__main { display: flex; flex: 1; min-width: 0; min-height: 0; flex-direction: column; }
.expert-chat__toolbar { display: flex; align-items: center; justify-content: space-between; min-height: 54px; padding: 7px 14px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .55); background: rgb(var(--v-theme-surface)); }
.expert-chat__conversation { font-weight: 800; text-transform: none; }
.expert-chat__toolbar-actions { display: flex; gap: 4px; }
.expert-chat__messages { display: flex; flex: 1; min-height: 0; flex-direction: column; gap: 24px; overflow-y: auto; padding: 28px max(22px, calc((100% - 820px) / 2)); scroll-behavior: smooth; }
.expert-chat__empty { display: grid; align-content: center; justify-items: center; min-height: 100%; padding: 24px; text-align: center; }
.expert-chat__empty-icon { display: grid; place-items: center; width: 54px; height: 54px; margin-bottom: 16px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); }
.expert-chat__empty h1 { margin: 0; font-size: clamp(1.35rem, 3vw, 1.85rem); letter-spacing: -.025em; }
.expert-chat__empty p { max-width: 540px; margin: 8px 0 24px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .82rem; }
.expert-chat__quick-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 260px)); gap: 9px; }
.expert-chat__quick-actions button { display: flex; align-items: center; gap: 9px; padding: 12px 13px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .84); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; font-size: .76rem; }
.expert-chat__quick-actions button:hover { border-color: rgba(var(--v-theme-primary), .52); background: rgba(var(--v-theme-primary), .045); }
@media (max-width: 700px) { .expert-chat__messages { gap: 18px; padding: 18px 12px; } .expert-chat__quick-actions { grid-template-columns: 1fr; width: 100%; } }
</style>
