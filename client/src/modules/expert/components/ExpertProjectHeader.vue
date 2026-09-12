<template>
  <header class="expert-project-header">
    <v-btn v-if="compact" icon="mdi-menu" variant="text" aria-label="Открыть навигацию проекта" @click="$emit('open-navigation')" />
    <div class="expert-project-header__identity">
      <div class="expert-project-header__title">{{ project.title }}</div>
      <div class="expert-project-header__meta">{{ project.direction }} · {{ project.workType }}</div>
    </div>
    <div class="expert-project-header__actions">
      <v-chip size="small" variant="tonal" prepend-icon="mdi-auto-fix">Prism AI · Auto</v-chip>
      <v-chip size="small" color="primary" variant="tonal" prepend-icon="mdi-circle-medium">{{ project.status }}</v-chip>
      <v-btn icon="mdi-dots-horizontal" variant="text" aria-label="Действия проекта" />
    </div>
  </header>
</template>

<script setup lang="ts">
import type { ExpertProject } from '../types'

defineProps<{ project: ExpertProject; compact: boolean }>()
defineEmits<{ (event: 'open-navigation'): void }>()
</script>

<style scoped>
.expert-project-header { display: flex; align-items: center; gap: 14px; min-height: 72px; padding: 12px 18px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .65); background: rgba(var(--v-theme-surface), .94); backdrop-filter: blur(14px); }
.expert-project-header__identity { min-width: 0; flex: 1; }
.expert-project-header__title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 1rem; font-weight: 800; color: rgb(var(--v-theme-on-surface)); }
.expert-project-header__meta { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .82); font-size: .76rem; }
.expert-project-header__actions { display: flex; align-items: center; gap: 8px; }
@media (max-width: 700px) { .expert-project-header { min-height: 64px; padding: 8px 10px; } .expert-project-header__meta { display: none; } .expert-project-header__actions .v-chip:first-child { display: none; } }
</style>
