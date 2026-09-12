<template>
  <div class="expert-composer">
    <div v-if="hasContextArea" class="expert-composer__contexts" aria-label="Контекст следующего запроса">
      <div v-if="uploadItems.length" class="expert-composer__uploads" aria-live="polite">
        <article v-for="item in uploadItems" :key="item.id" class="expert-composer__upload" :class="{ 'expert-composer__upload--error': item.state === 'error' }">
          <v-icon :icon="item.icon" size="18" />
          <span class="expert-composer__upload-name" :title="item.name">{{ item.name }}</span>
          <span class="expert-composer__upload-state">{{ uploadStateLabel(item.state) }}<template v-if="item.state === 'uploading'"> {{ item.progress }}%</template></span>
          <v-progress-circular v-if="item.state === 'queued' || item.state === 'uploading' || item.state === 'processing'" :model-value="item.state === 'uploading' ? item.progress : undefined" :indeterminate="item.state !== 'uploading'" size="15" width="2" :aria-label="`${uploadStateLabel(item.state)}: ${item.name}`" />
          <template v-else-if="item.state === 'error'">
            <v-btn size="x-small" variant="text" :aria-label="`Повторить загрузку: ${item.name}`" @click="$emit('retry-upload', item.id)">Повторить</v-btn>
            <v-btn icon="mdi-close" size="x-small" variant="text" :aria-label="`Убрать ошибочный файл: ${item.name}`" @click="$emit('remove-upload', item.id)" />
          </template>
        </article>
      </div>
      <div v-if="materialContexts.length" class="expert-composer__material-contexts">
        <span class="expert-composer__contexts-label">Материалы проекта</span>
        <v-chip v-for="context in materialContexts" :key="context.id" size="small" closable :prepend-icon="context.kind === 'image' && imagePreviews[context.id]?.status === 'ready' ? undefined : context.icon" :title="context.name" @click:close="$emit('remove-material-context', context.id)">
          <img v-if="context.kind === 'image' && imagePreviews[context.id]?.status === 'ready'" :src="imagePreviews[context.id]?.url" :alt="`Миниатюра: ${context.name}`" class="expert-composer__thumbnail" />
          <span class="expert-composer__material-name">{{ context.name }}</span>
        </v-chip>
      </div>
      <template v-if="contextChips.length">
        <v-chip v-for="context in contextChips" :key="context.id" size="small" closable :prepend-icon="context.icon" @click:close="$emit('remove-context', context.id)">{{ context.label }}</v-chip>
      </template>
      <v-btn v-else-if="allowWholeProjectContext" size="small" variant="tonal" prepend-icon="mdi-folder-multiple-outline" @click="$emit('select-whole-project')">Выбрать весь проект</v-btn>
    </div>
    <div class="expert-composer__box" :class="{ 'expert-composer__box--persistence': persistenceOnly, 'expert-composer__box--with-file-upload': allowFileUpload }">
      <input v-if="allowFileUpload" ref="fileInput" hidden type="file" multiple accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.webp" @change="attachFiles" />
      <v-btn v-if="allowFileUpload" icon="mdi-paperclip" variant="tonal" size="small" aria-label="Добавить файлы в проект" @click="fileInput?.click()" />
      <v-menu v-else-if="!persistenceOnly" location="top start" :close-on-content-click="true">
        <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" icon="mdi-plus" variant="tonal" size="small" aria-label="Добавить материал" /></template>
        <v-list density="compact" min-width="235">
          <v-list-item v-for="item in attachmentActions" :key="item.label" :prepend-icon="item.icon" :title="item.label" :subtitle="item.subtitle" @click="handleAttachment(item.action)" />
        </v-list>
      </v-menu>
      <textarea ref="textarea" v-model="text" rows="1" :placeholder="persistenceOnly ? 'Введите сообщение…' : 'Спросить Prism AI...'" :aria-label="persistenceOnly ? 'Сообщение' : 'Сообщение Prism AI'" :disabled="busy" aria-keyshortcuts="Enter" @input="resizeTextarea" @keydown="handleKeydown" />
      <v-select v-if="!persistenceOnly" v-model="mode" :items="modes" variant="plain" density="compact" hide-details class="expert-composer__mode" aria-label="Режим Prism AI" />
      <v-btn icon="mdi-arrow-up" color="primary" variant="flat" size="small" :loading="busy" :disabled="sendDisabled" aria-label="Отправить" @click="send" />
    </div>
    <div v-if="sendBlockedReason" class="expert-composer__blocked" role="status">{{ sendBlockedReason }}</div>
    <div class="expert-composer__hint">{{ persistenceOnly ? 'Материалы сохраняются в проекте. API чата пока не получает их IDs.' : 'Prism AI может ошибаться. Проверяйте выводы и источники.' }}</div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import type { ExpertChatContextChip } from '../../chatContext'
import { normalizeExpertChatDraft, shouldSubmitExpertChatComposer } from '../../chatComposer'
import type { ExpertMaterialImagePreview, ExpertMaterialUploadItem } from '../../composables/useExpertMaterialTransfers'
import type { ExpertMessageMaterialContext } from '../../types'

const props = withDefaults(defineProps<{
  contextChips: ExpertChatContextChip[]
  busy?: boolean
  persistenceOnly?: boolean
  allowWholeProjectContext?: boolean
  allowFileUpload?: boolean
  uploadItems?: ExpertMaterialUploadItem[]
  materialContexts?: ExpertMessageMaterialContext[]
  imagePreviews?: Record<string, ExpertMaterialImagePreview>
  sendBlockedReason?: string
}>(), {
  busy: false,
  persistenceOnly: false,
  allowWholeProjectContext: false,
  allowFileUpload: false,
  uploadItems: () => [],
  materialContexts: () => [],
  imagePreviews: () => ({}),
  sendBlockedReason: undefined,
})
const emit = defineEmits<{
  (event: 'send', text: string, accepted: () => void): void
  (event: 'attachment', action: string): void
  (event: 'attach-files', files: File[]): void
  (event: 'retry-upload', id: string): void
  (event: 'remove-upload', id: string): void
  (event: 'remove-material-context', id: string): void
  (event: 'remove-context', id: string): void
  (event: 'select-whole-project'): void
}>()
const text = ref('')
const textarea = ref<HTMLTextAreaElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const mode = ref('Auto')
const modes = ['Auto', 'Быстро', 'Глубокий анализ']
const attachmentActions = [
  { label: 'Камера', subtitle: 'Сделать снимок', icon: 'mdi-camera-outline', action: 'camera' },
  { label: 'Фото', subtitle: 'Выбрать изображения', icon: 'mdi-image-outline', action: 'photo' },
  { label: 'Файлы', subtitle: 'Документы и таблицы', icon: 'mdi-paperclip', action: 'file' },
  { label: 'Из материалов проекта', subtitle: 'Уже добавленные файлы', icon: 'mdi-folder-multiple-outline', action: 'materials' },
  { label: 'Норматив', subtitle: 'Подключённые источники', icon: 'mdi-book-open-page-variant-outline', action: 'normative' },
]
function send() {
  const value = normalizeExpertChatDraft(text.value)
  if (!value || props.busy || props.sendBlockedReason) return
  emit('send', value, () => {
    if (normalizeExpertChatDraft(text.value) === value) text.value = ''
  })
}
function handleKeydown(event: KeyboardEvent) {
  if (shouldSubmitExpertChatComposer(event)) {
    event.preventDefault()
    send()
  }
}
function resizeTextarea() {
  const element = textarea.value
  if (!element) return
  element.style.height = 'auto'
  const maxHeight = 144
  element.style.height = `${Math.min(element.scrollHeight, maxHeight)}px`
  element.style.overflowY = element.scrollHeight > maxHeight ? 'auto' : 'hidden'
}
function handleAttachment(action: string) { emit('attachment', action) }
function attachFiles(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (files.length) emit('attach-files', files)
  input.value = ''
}
function uploadStateLabel(state: ExpertMaterialUploadItem['state']) {
  return state === 'queued' ? 'В очереди' : state === 'uploading' ? 'Загружается' : state === 'processing' ? 'Обрабатывается' : state === 'completed' ? 'Загружен' : 'Ошибка загрузки'
}

watch(text, () => { void nextTick(resizeTextarea) })
onMounted(resizeTextarea)
const hasContextArea = computed(() => props.uploadItems.length > 0 || props.materialContexts.length > 0 || props.contextChips.length > 0 || props.allowWholeProjectContext)
const sendDisabled = computed(() => props.busy || Boolean(props.sendBlockedReason) || !normalizeExpertChatDraft(text.value))
</script>

<style scoped>
.expert-composer { padding: 10px 18px 12px; background: linear-gradient(0deg, rgb(var(--v-theme-background)) 78%, transparent); }
.expert-composer__contexts { display: flex; flex-wrap: wrap; gap: 6px; width: min(960px, 100%); max-height: 136px; margin: 0 auto 7px; overflow-y: auto; scrollbar-width: thin; }
.expert-composer__contexts :deep(.v-chip), .expert-composer__contexts :deep(.v-btn) { flex: 0 0 auto; }
.expert-composer__uploads { display: grid; width: 100%; gap: 5px; }
.expert-composer__upload { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 7px; min-height: 30px; padding: 4px 7px; border-radius: var(--md-sys-shape-corner-small); color: rgba(var(--v-theme-on-surface), .86); background: rgb(var(--v-theme-surface-container-low)); font-size: .7rem; }
.expert-composer__upload--error { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .08); }
.expert-composer__upload-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-composer__upload-state { color: rgba(var(--v-theme-on-surface-variant), .76); white-space: nowrap; font-size: .66rem; }
.expert-composer__upload--error .expert-composer__upload-state { color: currentColor; }
.expert-composer__material-contexts { display: flex; align-items: center; gap: 6px; min-width: 0; max-width: 100%; }
.expert-composer__contexts-label { flex: 0 0 auto; color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .66rem; font-weight: 700; }
.expert-composer__material-name { display: inline-block; max-width: 210px; overflow: hidden; text-overflow: ellipsis; vertical-align: bottom; white-space: nowrap; }
.expert-composer__thumbnail { width: 17px; height: 17px; margin-right: 5px; border-radius: 3px; object-fit: cover; vertical-align: middle; }
.expert-composer__box { display: grid; grid-template-columns: auto minmax(120px, 1fr) 128px auto; align-items: end; gap: 8px; width: min(960px, 100%); margin: 0 auto; padding: 10px; border: 1px solid rgba(var(--v-theme-outline), .34); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface)); box-shadow: var(--ds-shadow-soft); transition: border-color .15s ease, box-shadow .15s ease; }
.expert-composer__box--persistence { grid-template-columns: minmax(120px, 1fr) auto; }
.expert-composer__box--persistence.expert-composer__box--with-file-upload { grid-template-columns: auto minmax(120px, 1fr) auto; }
.expert-composer__box:focus-within { border-color: rgba(var(--v-theme-primary), .74); box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), .12), var(--ds-shadow-soft); }
.expert-composer textarea { align-self: center; width: 100%; min-height: 34px; max-height: 144px; padding: 7px 2px; resize: none; outline: none; border: 0; color: rgb(var(--v-theme-on-surface)); background: transparent; font: inherit; font-size: .86rem; line-height: 1.4; }
.expert-composer__mode { align-self: center; font-size: .75rem; }
.expert-composer__blocked { width: min(960px, 100%); margin: 6px auto 0; color: rgb(var(--v-theme-error)); font-size: .7rem; }
.expert-composer__hint { margin-top: 6px; color: rgba(var(--v-theme-on-surface-variant), .62); text-align: center; font-size: .64rem; }
@media (max-width: 600px) { .expert-composer { padding: 8px; } .expert-composer__contexts { max-height: 128px; margin-bottom: 6px; } .expert-composer__material-contexts { width: 100%; } .expert-composer__material-name { max-width: 145px; } .expert-composer__box { grid-template-columns: auto minmax(80px, 1fr) auto; border-radius: var(--md-sys-shape-corner-large); } .expert-composer__box--persistence { grid-template-columns: minmax(80px, 1fr) auto; } .expert-composer__box--persistence.expert-composer__box--with-file-upload { grid-template-columns: auto minmax(80px, 1fr) auto; } .expert-composer__mode { display: none; } .expert-composer__hint { display: none; } }
</style>
