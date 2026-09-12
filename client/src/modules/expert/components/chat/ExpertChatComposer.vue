<template>
  <div class="expert-composer">
    <div v-if="selectedMaterials.length" class="expert-composer__attachments">
      <v-chip v-for="material in selectedMaterials" :key="material.id" size="small" closable :prepend-icon="material.icon" @click:close="$emit('remove-material', material.id)">{{ material.name }}</v-chip>
    </div>
    <div class="expert-composer__box">
      <v-menu location="top start" :close-on-content-click="true">
        <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" icon="mdi-plus" variant="tonal" size="small" aria-label="Добавить материал" /></template>
        <v-list density="compact" min-width="235">
          <v-list-item v-for="item in attachmentActions" :key="item.label" :prepend-icon="item.icon" :title="item.label" :subtitle="item.subtitle" @click="handleAttachment(item.action)" />
        </v-list>
      </v-menu>
      <textarea v-model="text" rows="1" placeholder="Спросить Prism AI..." aria-label="Сообщение Prism AI" @keydown.enter.exact.prevent="send" />
      <v-select v-model="mode" :items="modes" variant="plain" density="compact" hide-details class="expert-composer__mode" aria-label="Режим Prism AI" />
      <v-btn icon="mdi-arrow-up" color="primary" variant="flat" size="small" :disabled="!text.trim() && !selectedMaterials.length" aria-label="Отправить" @click="send" />
    </div>
    <div class="expert-composer__hint">Prism AI может ошибаться. Проверяйте выводы и источники.</div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { ExpertProjectMaterial } from '../../types'

defineProps<{ selectedMaterials: ExpertProjectMaterial[] }>()
const emit = defineEmits<{ (event: 'send', text: string): void; (event: 'attachment', action: string): void; (event: 'remove-material', id: string): void }>()
const text = ref('')
const mode = ref('Auto')
const modes = ['Auto', 'Быстро', 'Глубокий анализ']
const attachmentActions = [
  { label: 'Камера', subtitle: 'Сделать снимок', icon: 'mdi-camera-outline', action: 'camera' },
  { label: 'Фото', subtitle: 'Выбрать изображения', icon: 'mdi-image-outline', action: 'photo' },
  { label: 'Файлы', subtitle: 'Документы и таблицы', icon: 'mdi-paperclip', action: 'file' },
  { label: 'Из материалов проекта', subtitle: 'Уже добавленные файлы', icon: 'mdi-folder-multiple-outline', action: 'materials' },
  { label: 'Норматив', subtitle: 'Подключённые источники', icon: 'mdi-book-open-page-variant-outline', action: 'normative' },
]
function send() { const value = text.value.trim(); if (!value) return; emit('send', value); text.value = '' }
function handleAttachment(action: string) { emit('attachment', action) }
</script>

<style scoped>
.expert-composer { padding: 10px 18px 12px; background: linear-gradient(0deg, rgb(var(--v-theme-background)) 78%, transparent); }
.expert-composer__attachments { display: flex; flex-wrap: wrap; gap: 6px; width: min(820px, 100%); margin: 0 auto 7px; }
.expert-composer__box { display: grid; grid-template-columns: auto minmax(120px, 1fr) 128px auto; align-items: end; gap: 8px; width: min(820px, 100%); margin: 0 auto; padding: 10px; border: 1px solid rgba(var(--v-theme-outline), .34); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface)); box-shadow: var(--ds-shadow-soft); }
.expert-composer textarea { align-self: center; width: 100%; max-height: 120px; padding: 7px 2px; resize: none; outline: none; border: 0; color: rgb(var(--v-theme-on-surface)); background: transparent; font: inherit; font-size: .86rem; }
.expert-composer__mode { align-self: center; font-size: .75rem; }
.expert-composer__hint { margin-top: 6px; color: rgba(var(--v-theme-on-surface-variant), .62); text-align: center; font-size: .64rem; }
@media (max-width: 600px) { .expert-composer { padding: 8px 58px 8px 8px; } .expert-composer__box { grid-template-columns: auto minmax(80px, 1fr) auto; border-radius: var(--md-sys-shape-corner-large); } .expert-composer__mode { display: none; } .expert-composer__hint { display: none; } }
</style>
