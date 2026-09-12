<template>
  <aside class="expert-context-panel">
    <div class="expert-context-panel__header">
      <div><span>Контекст</span><small>{{ project.materials.length }} материалов подключено</small></div>
      <v-btn icon="mdi-chevron-right" size="small" variant="text" aria-label="Свернуть контекст" @click="$emit('close')" />
    </div>
    <v-tabs v-model="tab" density="compact" grow color="primary">
      <v-tab value="sources">Источники</v-tab><v-tab value="research">Исследование</v-tab><v-tab value="report">Документ</v-tab>
    </v-tabs>
    <v-divider />
    <div class="expert-context-panel__content">
      <template v-if="tab === 'sources'">
        <button v-for="material in project.materials.slice(0, 5)" :key="material.id" class="expert-context-panel__item" type="button" @click="$emit('action', material.name)">
          <v-icon :icon="material.icon" size="19" /><span><strong>{{ material.name }}</strong><small>{{ material.meta }}</small></span><v-icon icon="mdi-chevron-right" size="16" />
        </button>
      </template>
      <template v-else-if="tab === 'research'">
        <button v-for="finding in project.findings" :key="finding.id" class="expert-context-panel__finding" type="button" @click="$emit('action', finding.title)">
          <span class="expert-context-panel__number">{{ finding.number }}</span><span><strong>{{ finding.typeLabel }}</strong><small>{{ finding.title }}</small></span>
        </button>
      </template>
      <template v-else>
        <div class="expert-context-panel__document">
          <v-icon icon="mdi-file-document-edit-outline" size="28" />
          <strong>Заключение {{ project.revisions[0]?.label }}</strong>
          <span>Готовность документа — 68%</span>
          <v-progress-linear :model-value="68" color="primary" rounded height="6" />
          <v-btn size="small" variant="tonal" color="primary" :to="{ name: 'expert-project-report', params: { projectId: project.id } }">Открыть документ</v-btn>
        </div>
      </template>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { ExpertProject } from '../../types'

defineProps<{ project: ExpertProject }>()
defineEmits<{ (event: 'close'): void; (event: 'action', label: string): void }>()
const tab = ref('sources')
</script>

<style scoped>
.expert-context-panel { display: flex; flex-direction: column; width: 310px; min-width: 280px; height: 100%; border-left: 1px solid rgba(var(--v-theme-outline-variant), .6); background: rgb(var(--v-theme-surface)); }
.expert-context-panel__header { display: flex; align-items: center; justify-content: space-between; min-height: 58px; padding: 10px 10px 8px 16px; }
.expert-context-panel__header span, .expert-context-panel__header small { display: block; }
.expert-context-panel__header span { font-size: .86rem; font-weight: 800; }
.expert-context-panel__header small { margin-top: 2px; color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .67rem; }
.expert-context-panel__content { flex: 1; overflow: auto; padding: 10px; }
.expert-context-panel :deep(.v-tabs) { flex: 0 0 auto; }
.expert-context-panel :deep(.v-tab) { min-width: 0; padding-inline: 7px; font-size: .66rem; }
.expert-context-panel__item { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 9px; width: 100%; padding: 10px 8px; border: 0; border-radius: var(--md-sys-shape-corner-medium); background: transparent; color: rgb(var(--v-theme-on-surface)); cursor: pointer; text-align: left; }
.expert-context-panel__item:hover, .expert-context-panel__finding:hover { background: rgba(var(--v-theme-on-surface), .05); }
.expert-context-panel__item strong, .expert-context-panel__item small, .expert-context-panel__finding strong, .expert-context-panel__finding small { display: block; overflow: hidden; text-overflow: ellipsis; }
.expert-context-panel__item strong { font-size: .74rem; white-space: nowrap; }
.expert-context-panel__item small { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .65rem; }
.expert-context-panel__finding { display: flex; gap: 10px; width: 100%; padding: 10px 8px; border: 0; border-radius: var(--md-sys-shape-corner-medium); background: transparent; color: rgb(var(--v-theme-on-surface)); cursor: pointer; text-align: left; }
.expert-context-panel__number { display: grid; place-items: center; flex: 0 0 26px; height: 26px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); font-size: .7rem; font-weight: 800; }
.expert-context-panel__finding strong { font-size: .72rem; }
.expert-context-panel__finding small { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .78); font-size: .69rem; line-height: 1.35; }
.expert-context-panel__document { display: grid; justify-items: start; gap: 10px; padding: 16px; border-radius: var(--md-sys-shape-corner-large); background: rgb(var(--v-theme-surface-container-low)); font-size: .76rem; }
.expert-context-panel__document span { color: rgba(var(--v-theme-on-surface-variant), .76); }
.expert-context-panel__document .v-progress-linear { width: 100%; }
@media (max-width: 1280px) { .expert-context-panel { width: 280px; } }
</style>
