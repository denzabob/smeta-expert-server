<template>
  <article class="expert-message" :class="[`expert-message--${message.role}`, { 'expert-message--error': message.deliveryState === 'error' }]">
    <div v-if="message.role === 'assistant'" class="expert-message__avatar"><v-icon icon="mdi-prism" size="19" /></div>
    <div class="expert-message__body">
      <div class="expert-message__author">{{ message.role === 'assistant' ? 'Призма' : 'Вы' }} <span>{{ formattedTimestamp }}</span></div>
      <div v-if="message.role === 'user'" class="expert-message__bubble">{{ message.text }}</div>
      <div v-else-if="message.text" class="expert-message__bubble expert-message__markdown" v-html="renderedMarkdown" />
      <div v-if="message.role === 'user' && displayAttachments.length" class="expert-message__attachments" aria-label="Вложения сообщения">
        <button v-for="attachment in displayAttachments" :key="attachment.id" type="button" class="expert-message__attachment" :disabled="!attachment.available" :title="attachment.available ? attachment.name : `${attachment.name}: материал удалён`" @click="$emit('open-material', attachment.id)">
          <img v-if="attachment.kind === 'image' && imagePreviews[attachment.id]?.status === 'ready'" :src="imagePreviews[attachment.id]?.url" :alt="`Миниатюра: ${attachment.name}`" />
          <v-icon v-else :icon="attachment.icon" size="18" />
          <span>{{ attachment.name }}</span>
          <small v-if="!attachment.available">Материал удалён</small>
        </button>
      </div>
      <ExpertChatActivityTimeline v-if="message.role === 'assistant' && message.deliveryState === 'sending' && !message.text" :runs="timelineRuns" />
      <div v-if="message.role === 'user' && message.deliveryState === 'error'" class="expert-message__delivery expert-message__delivery--error" role="alert">
        <span>{{ displayError }}</span>
        <v-btn size="x-small" variant="text" @click="$emit('retry', message.id)">Повторить</v-btn>
        <details v-if="message.diagnostic" class="expert-message__diagnostic">
          <summary>Подробнее</summary>
          <span>Код: {{ message.diagnostic.errorCode }}</span>
          <span>ID: {{ message.diagnostic.runId }}</span>
          <span v-if="message.diagnostic.lastActivityCode">Этап: {{ message.diagnostic.lastActivityCode }}</span>
          <button type="button" :aria-label="`Копировать diagnostic ID ${message.diagnostic.runId}`" @click="copyDiagnosticId(message.diagnostic.runId)">
            <v-icon :icon="copiedDiagnosticRunId === message.diagnostic.runId ? 'mdi-check' : 'mdi-content-copy'" size="14" />
          </button>
        </details>
      </div>
      <div v-else-if="message.role === 'user' && message.deliveryState === 'sent'" class="expert-message__delivery">
        <v-icon icon="mdi-check" size="13" /> Отправлено
      </div>
      <div v-if="message.role === 'assistant' && message.deliveryState === 'error'" class="expert-message__delivery expert-message__delivery--error" role="alert">
        <span>{{ displayError }}</span>
        <v-btn v-if="canContinue" size="x-small" variant="text" @click="$emit('continue', message.id)">Повторить</v-btn>
        <details v-if="message.diagnostic" class="expert-message__diagnostic">
          <summary>Подробнее</summary>
          <span>Код: {{ message.diagnostic.errorCode }}</span>
          <span>ID: {{ message.diagnostic.runId }}</span>
          <span v-if="message.diagnostic.lastActivityCode">Этап: {{ message.diagnostic.lastActivityCode }}</span>
          <button type="button" :aria-label="`Копировать diagnostic ID ${message.diagnostic.runId}`" @click="copyDiagnosticId(message.diagnostic.runId)">
            <v-icon :icon="copiedDiagnosticRunId === message.diagnostic.runId ? 'mdi-check' : 'mdi-content-copy'" size="14" />
          </button>
        </details>
      </div>
      <div v-if="message.sources?.length" class="expert-message__sources">
        <div class="expert-message__sources-label">Источники</div>
        <button v-for="source in message.sources" :key="source.id" class="expert-message__source" type="button" @click="$emit('open-source', source.id)">
          <v-icon :icon="source.icon || 'mdi-file-outline'" size="17" />
          <span>{{ source.label }}</span><small v-if="source.detail">{{ source.detail }}</small>
        </button>
      </div>
      <div v-if="message.role === 'assistant'" class="expert-message__actions">
        <v-btn v-if="canContinue && message.deliveryState !== 'error'" size="x-small" variant="text" prepend-icon="mdi-play" @click="$emit('continue', message.id)">Продолжить</v-btn>
        <v-btn size="x-small" variant="text" prepend-icon="mdi-content-copy" @click="$emit('action', 'Копировать')">Копировать</v-btn>
        <v-menu location="bottom start">
          <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" size="x-small" variant="text" append-icon="mdi-chevron-down">Добавить</v-btn></template>
          <v-list density="compact" min-width="220">
            <v-list-item v-for="action in actions" :key="action.label" :prepend-icon="action.icon" :title="action.label" @click="$emit('action', action.label)" />
          </v-list>
        </v-menu>
      </div>
      <div v-if="canRate" class="expert-message__feedback" aria-label="Оценить ответ">
        <v-btn icon="mdi-thumb-up-outline" size="x-small" variant="text" aria-label="Хороший ответ" :aria-pressed="message.feedback?.rating === 'positive'" :disabled="feedbackSaving" @click="rate('positive')" />
        <v-btn icon="mdi-thumb-down-outline" size="x-small" variant="text" aria-label="Плохой ответ" :aria-pressed="message.feedback?.rating === 'negative'" :disabled="feedbackSaving" @click="rate('negative')" />
        <span v-if="feedbackError" class="expert-message__feedback-error" role="alert">{{ feedbackError }}</span>
      </div>
    </div>
    <v-dialog v-model="feedbackDialog" max-width="430">
      <v-card>
        <v-card-title>Что было не так?</v-card-title>
        <v-card-text>
          <div class="expert-message__feedback-reasons">
            <button v-for="reason in feedbackReasons" :key="reason.code" type="button" :aria-pressed="selectedReason === reason.code" @click="selectedReason = reason.code">{{ reason.label }}</button>
          </div>
          <label class="expert-message__feedback-comment">Комментарий (необязательно)<textarea v-model="feedbackComment" rows="3" maxlength="2000" /></label>
          <p class="expert-message__feedback-privacy">Оценка и комментарий остаются внутри PrismCore и доступны администраторам.</p>
          <p v-if="feedbackDialogError" role="alert" class="expert-message__feedback-error">{{ feedbackDialogError }}</p>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn variant="text" @click="feedbackDialog = false">Закрыть</v-btn><v-btn color="primary" :loading="feedbackSaving" @click="submitFeedback">Отправить</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </article>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { ExpertMessage, ExpertMessageFeedback } from '../../types'
import type { ExpertMaterialImagePreview } from '../../composables/useExpertMaterialTransfers'
import { renderExpertAssistantMarkdown } from '../../chatMarkdown'
import { formatExpertMessageTimestamp } from '../../chatPresentation'
import { expertApi, mapExpertApiError } from '../../api'
import ExpertChatActivityTimeline from './ExpertChatActivityTimeline.vue'
import type { ExpertTimelineRun } from '../../chatTimeline'

const props = withDefaults(defineProps<{ message: ExpertMessage; allowContinue?: boolean; feedbackEnabled?: boolean; timelineRuns?: ExpertTimelineRun[]; showSlowWaiting?: boolean; imagePreviews?: Record<string, ExpertMaterialImagePreview> }>(), { allowContinue: false, feedbackEnabled: false, timelineRuns: () => [], showSlowWaiting: false, imagePreviews: () => ({}) })
const emit = defineEmits<{ (event: 'action', action: string): void; (event: 'open-source', sourceId: string): void; (event: 'open-material', materialId: string): void; (event: 'retry', messageId: string): void; (event: 'continue', messageId: string): void; (event: 'feedback-updated', messageId: string, feedback: ExpertMessageFeedback | null): void }>()
const displayAttachments = computed(() => props.message.attachments ?? (props.message.runtimeMaterialContext ?? []).map((item) => ({ ...item, available: true, mimeType: '', sizeBytes: 0 })))
const formattedTimestamp = computed(() => formatExpertMessageTimestamp(props.message.createdAt))
const displayError = computed(() => props.message.diagnostic?.errorCode.startsWith('pdf_') ? 'Не удалось обработать документ.' : (props.message.deliveryError || 'Не удалось обработать запрос.'))
const canContinue = computed(() => props.allowContinue && (props.message.deliveryState === 'error' || props.message.generationStatus === 'stopped' || props.message.generationStatus === 'interrupted'))
const canRate = computed(() => props.feedbackEnabled && props.message.role === 'assistant' && props.message.text.trim() !== '' && props.message.deliveryState !== 'sending' && props.message.deliveryState !== 'error' && !['stopped', 'interrupted'].includes(props.message.generationStatus ?? 'completed'))
const feedbackSaving = ref(false)
const feedbackError = ref('')
const feedbackDialogError = ref('')
const feedbackDialog = ref(false)
const selectedReason = ref<string | null>(null)
const feedbackComment = ref('')
const feedbackReasons = [
  { code: 'incorrect_or_incomplete', label: 'Неправильно или неполно' },
  { code: 'not_requested', label: 'Не то, что я просил' },
  { code: 'material_analysis_error', label: 'Ошибка в анализе материалов' },
  { code: 'too_slow', label: 'Слишком медленно' },
  { code: 'style_or_formatting', label: 'Стиль / оформление' },
  { code: 'other', label: 'Другое' },
]
async function rate(rating: ExpertMessageFeedback['rating']) {
  if (feedbackSaving.value) return
  feedbackError.value = ''
  feedbackSaving.value = true
  try {
    if (props.message.feedback?.rating === rating) {
      await expertApi.deleteMessageFeedback(props.message.id)
      emit('feedback-updated', props.message.id, null)
      feedbackDialog.value = false
    } else {
      const saved = await expertApi.saveMessageFeedback(props.message.id, { rating })
      emit('feedback-updated', props.message.id, saved)
      if (rating === 'negative') {
        selectedReason.value = saved.reasonCode ?? null
        feedbackComment.value = saved.comment ?? ''
        feedbackDialog.value = true
      } else {
        feedbackDialog.value = false
      }
    }
  } catch (error) {
    feedbackError.value = mapExpertApiError(error).message
  } finally {
    feedbackSaving.value = false
  }
}
async function submitFeedback() {
  if (feedbackSaving.value) return
  feedbackDialogError.value = ''
  feedbackSaving.value = true
  try {
    const saved = await expertApi.saveMessageFeedback(props.message.id, { rating: 'negative', reasonCode: selectedReason.value, comment: feedbackComment.value.trim() || null })
    emit('feedback-updated', props.message.id, saved)
    feedbackDialog.value = false
  } catch (error) {
    feedbackDialogError.value = mapExpertApiError(error).message
  } finally {
    feedbackSaving.value = false
  }
}
const copiedDiagnosticRunId = ref('')
const renderedMarkdown = ref('')
let renderTimer: ReturnType<typeof setTimeout> | undefined
watch(() => [props.message.text, props.message.deliveryState], () => {
  if (props.message.role !== 'assistant') return
  if (renderTimer !== undefined) clearTimeout(renderTimer)
  if (props.message.deliveryState === 'sending') {
    renderTimer = setTimeout(() => {
      renderedMarkdown.value = renderExpertAssistantMarkdown(props.message.text)
      renderTimer = undefined
    }, 40)
  } else {
    renderedMarkdown.value = renderExpertAssistantMarkdown(props.message.text)
    renderTimer = undefined
  }
}, { immediate: true })
onBeforeUnmount(() => { if (renderTimer !== undefined) clearTimeout(renderTimer) })
const actions = [
  { label: 'Добавить как факт', icon: 'mdi-pin-outline' },
  { label: 'Добавить в исследование', icon: 'mdi-microscope' },
  { label: 'Добавить в заключение', icon: 'mdi-file-document-edit-outline' },
  { label: 'Открыть источники', icon: 'mdi-folder-open-outline' },
]

async function copyDiagnosticId(runId: string) {
  try {
    await navigator.clipboard?.writeText(runId)
    copiedDiagnosticRunId.value = runId
  } catch {
    copiedDiagnosticRunId.value = ''
  }
}
</script>

<style scoped>
.expert-message { display: flex; gap: 12px; width: min(820px, 100%); }
.expert-message--user { align-self: flex-end; justify-content: flex-end; width: min(680px, 76%); }
.expert-message__avatar { display: grid; place-items: center; flex: 0 0 34px; height: 34px; margin-top: 21px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); }
.expert-message__body { min-width: 0; }
.expert-message--user .expert-message__body { display: flex; flex-direction: column; align-items: flex-end; }
.expert-message__author { margin: 0 8px 6px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .7rem; font-weight: 700; }
.expert-message__author span { margin-left: 5px; font-weight: 500; opacity: .75; }
.expert-message__bubble { padding: 13px 15px; border: 0; border-radius: 6px var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .9); background: rgb(var(--v-theme-surface-container-low)); line-height: 1.55; white-space: pre-line; }
.expert-message__markdown { white-space: normal; overflow-wrap: anywhere; }
.expert-message__markdown :deep(ul), .expert-message__markdown :deep(ol) { margin-inline: 0; padding-inline-start: 1.65rem; list-style-position: outside; }
.expert-message__markdown :deep(li) { padding-inline-start: .15rem; overflow-wrap: anywhere; }
.expert-message__markdown :deep(li > ul), .expert-message__markdown :deep(li > ol) { margin-block: .35rem; padding-inline-start: 1.45rem; }
.expert-message__markdown :deep(:first-child) { margin-top: 0; }
.expert-message__markdown :deep(:last-child) { margin-bottom: 0; }
.expert-message__markdown :deep(pre) { overflow-x: auto; padding: 10px; border-radius: var(--md-sys-shape-corner-small); background: rgb(var(--v-theme-surface-container)); }
.expert-message__markdown :deep(code) { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.expert-message__markdown :deep(blockquote) { margin-left: 0; padding-left: 12px; border-left: 3px solid rgb(var(--v-theme-outline-variant)); }
.expert-message__markdown :deep(table) { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; }
.expert-message__markdown :deep(th), .expert-message__markdown :deep(td) { padding: 4px 8px; border: 1px solid rgb(var(--v-theme-outline-variant)); }
.expert-message__attachments { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; max-width: 100%; margin-top: 7px; }
.expert-message__attachment { display: inline-flex; align-items: center; gap: 6px; max-width: 240px; min-height: 32px; padding: 4px 8px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-on-surface)); background: rgb(var(--v-theme-surface-container-low)); cursor: pointer; font: inherit; font-size: .72rem; }
.expert-message__attachment span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-message__attachment img { width: 25px; height: 25px; border-radius: 3px; object-fit: cover; }
.expert-message__attachment small { white-space: nowrap; font-size: .62rem; }
.expert-message__attachment:disabled { opacity: .65; cursor: default; }
.expert-message__attachment:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.expert-message--user .expert-message__bubble { border: 0; border-radius: var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-small) var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-large); background: rgb(var(--v-theme-secondary-container)); color: rgb(var(--v-theme-on-secondary-container)); }
.expert-message__delivery { display: inline-flex; align-items: center; gap: 5px; margin: 5px 8px 0; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .68rem; }
.expert-message__delivery--error { color: rgb(var(--v-theme-error)); }
.expert-message__delivery--error :deep(.v-btn) { min-width: 0; margin-left: 2px; color: currentColor; text-transform: none; }
.expert-message__diagnostic { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px 7px; margin-left: 2px; }
.expert-message__diagnostic summary { cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
.expert-message__diagnostic span { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .64rem; }
.expert-message__diagnostic button { display: inline-grid; place-items: center; width: 22px; height: 22px; border: 0; border-radius: var(--md-sys-shape-corner-small); color: inherit; background: transparent; cursor: pointer; }
.expert-message__diagnostic button:hover { background: rgba(var(--v-theme-error), .1); }
.expert-message__diagnostic button:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
.expert-message__sources { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
.expert-message__sources-label { width: 100%; color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.expert-message__source { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 6px 9px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-medium); color: rgba(var(--v-theme-on-surface), .85); background: rgb(var(--v-theme-surface-container-low)); cursor: pointer; font: inherit; font-size: .72rem; }
.expert-message__source:hover { border-color: rgba(var(--v-theme-primary), .55); background: rgba(var(--v-theme-primary), .06); }
.expert-message__source small { color: rgba(var(--v-theme-on-surface-variant), .7); }
.expert-message__actions { display: flex; align-items: center; gap: 2px; margin-top: 4px; }
.expert-message__feedback { display: flex; align-items: center; gap: 2px; margin-top: 3px; }
.expert-message__feedback-error { color: rgb(var(--v-theme-error)); font-size: .72rem; }
.expert-message__feedback-reasons { display: flex; flex-wrap: wrap; gap: 6px; }
.expert-message__feedback-reasons button { padding: 6px 9px; border: 1px solid rgb(var(--v-theme-outline-variant)); border-radius: var(--md-sys-shape-corner-medium); background: transparent; color: inherit; cursor: pointer; font: inherit; }
.expert-message__feedback-reasons button[aria-pressed="true"] { border-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .08); }
.expert-message__feedback-comment { display: grid; gap: 5px; margin-top: 14px; }
.expert-message__feedback-comment textarea { width: 100%; padding: 8px; border: 1px solid rgb(var(--v-theme-outline-variant)); border-radius: var(--md-sys-shape-corner-small); color: inherit; background: transparent; font: inherit; resize: vertical; }
.expert-message__feedback-privacy { margin: 8px 0 0; color: rgb(var(--v-theme-on-surface-variant)); font-size: .72rem; }
@media (max-width: 600px) { .expert-message--user { width: 88%; } .expert-message__avatar { display: none; } }
</style>
