<template>
  <nav class="expert-project-nav" aria-label="Разделы экспертного проекта">
    <div class="expert-project-nav__label">Проект</div>
    <router-link v-for="item in items" :key="item.name" :to="{ name: item.name, params: { projectId } }" class="expert-project-nav__item" exact-active-class="expert-project-nav__item--active" @click="$emit('navigated')">
      <v-icon :icon="item.icon" size="19" />
      <span>{{ item.label }}</span>
    </router-link>
    <div class="expert-project-nav__footer">
      <v-icon icon="mdi-cloud-check-outline" size="18" />
      <span>{{ projectMode === 'demo' ? 'Демонстрационный проект' : 'Данные проекта загружены' }}</span>
    </div>
  </nav>
</template>

<script setup lang="ts">
import type { ExpertProjectMode } from '../types'
defineProps<{ projectId: string; projectMode: ExpertProjectMode }>()
defineEmits<{ (event: 'navigated'): void }>()

const items = [
  { name: 'expert-project-overview', label: 'Обзор', icon: 'mdi-view-dashboard-outline' },
  { name: 'expert-project-chat', label: 'Чат', icon: 'mdi-message-text-outline' },
  { name: 'expert-project-materials', label: 'Материалы', icon: 'mdi-folder-multiple-outline' },
  { name: 'expert-project-research', label: 'Исследование', icon: 'mdi-microscope' },
  { name: 'expert-project-normatives', label: 'Нормативы', icon: 'mdi-book-open-page-variant-outline' },
  { name: 'expert-project-report', label: 'Заключение', icon: 'mdi-file-document-edit-outline' },
  { name: 'expert-project-versions', label: 'Версии', icon: 'mdi-history' },
] as const
</script>

<style scoped>
.expert-project-nav { display: flex; flex-direction: column; width: 218px; height: 100%; padding: 18px 12px 14px; border-right: 1px solid rgba(var(--v-theme-outline-variant), .65); background: rgb(var(--v-theme-surface-container-low)); }
.expert-project-nav__label { padding: 0 12px 10px; color: rgba(var(--v-theme-on-surface-variant), .7); font-size: .68rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
.expert-project-nav__item { display: flex; align-items: center; gap: 11px; min-height: 42px; margin-bottom: 3px; padding: 0 12px; border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .78); text-decoration: none; font-size: .84rem; font-weight: 650; transition: background-color .15s ease, color .15s ease; }
.expert-project-nav__item:hover { background: rgba(var(--v-theme-on-surface), .055); }
.expert-project-nav__item--active { color: rgb(var(--v-theme-on-secondary-container)); background: rgb(var(--v-theme-secondary-container)); }
.expert-project-nav__footer { display: flex; align-items: center; gap: 8px; margin-top: auto; padding: 12px; color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .7rem; }
</style>
