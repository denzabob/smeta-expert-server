<template>
  <article class="expert-message" :class="`expert-message--${message.role}`">
    <div v-if="message.role === 'assistant'" class="expert-message__avatar"><v-icon icon="mdi-prism" size="19" /></div>
    <div class="expert-message__body">
      <div class="expert-message__author">{{ message.role === 'assistant' ? 'Prism AI' : 'Вы' }} <span>{{ message.createdAt }}</span></div>
      <div class="expert-message__bubble">{{ message.text }}</div>
      <div v-if="message.sources?.length" class="expert-message__sources">
        <div class="expert-message__sources-label">Источники</div>
        <button v-for="source in message.sources" :key="source.id" class="expert-message__source" type="button" @click="$emit('open-source', source.id)">
          <v-icon :icon="source.icon || 'mdi-file-outline'" size="17" />
          <span>{{ source.label }}</span><small v-if="source.detail">{{ source.detail }}</small>
        </button>
      </div>
      <div v-if="message.role === 'assistant'" class="expert-message__actions">
        <v-btn size="x-small" variant="text" prepend-icon="mdi-content-copy" @click="$emit('action', 'Копировать')">Копировать</v-btn>
        <v-menu location="bottom start">
          <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" size="x-small" variant="text" append-icon="mdi-chevron-down">Добавить</v-btn></template>
          <v-list density="compact" min-width="220">
            <v-list-item v-for="action in actions" :key="action.label" :prepend-icon="action.icon" :title="action.label" @click="$emit('action', action.label)" />
          </v-list>
        </v-menu>
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { ExpertMessage } from '../../types'

defineProps<{ message: ExpertMessage }>()
defineEmits<{ (event: 'action', action: string): void; (event: 'open-source', sourceId: string): void }>()
const actions = [
  { label: 'Добавить как факт', icon: 'mdi-pin-outline' },
  { label: 'Добавить в исследование', icon: 'mdi-microscope' },
  { label: 'Добавить в заключение', icon: 'mdi-file-document-edit-outline' },
  { label: 'Открыть источники', icon: 'mdi-folder-open-outline' },
]
</script>

<style scoped>
.expert-message { display: flex; gap: 12px; width: min(760px, 100%); }
.expert-message--user { align-self: flex-end; justify-content: flex-end; width: min(620px, 88%); }
.expert-message__avatar { display: grid; place-items: center; flex: 0 0 34px; height: 34px; margin-top: 21px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); }
.expert-message__body { min-width: 0; }
.expert-message--user .expert-message__body { display: flex; flex-direction: column; align-items: flex-end; }
.expert-message__author { margin: 0 8px 6px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .7rem; font-weight: 700; }
.expert-message__author span { margin-left: 5px; font-weight: 500; opacity: .75; }
.expert-message__bubble { padding: 13px 15px; border: 1px solid rgba(var(--v-theme-outline-variant), .56); border-radius: 6px var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .9); background: rgb(var(--v-theme-surface)); font-size: .86rem; line-height: 1.55; white-space: pre-line; }
.expert-message--user .expert-message__bubble { border: 0; border-radius: var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-small) var(--md-sys-shape-corner-large) var(--md-sys-shape-corner-large); background: rgb(var(--v-theme-secondary-container)); color: rgb(var(--v-theme-on-secondary-container)); }
.expert-message__sources { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 10px; }
.expert-message__sources-label { width: 100%; color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.expert-message__source { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 6px 9px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-medium); color: rgba(var(--v-theme-on-surface), .85); background: rgb(var(--v-theme-surface-container-low)); cursor: pointer; font: inherit; font-size: .72rem; }
.expert-message__source:hover { border-color: rgba(var(--v-theme-primary), .55); background: rgba(var(--v-theme-primary), .06); }
.expert-message__source small { color: rgba(var(--v-theme-on-surface-variant), .7); }
.expert-message__actions { display: flex; align-items: center; gap: 2px; margin-top: 4px; }
@media (max-width: 600px) { .expert-message--user { width: 92%; } .expert-message__avatar { display: none; } .expert-message__bubble { font-size: .82rem; } }
</style>
