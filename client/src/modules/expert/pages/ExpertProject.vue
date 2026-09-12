<template>
  <div v-if="project" class="expert-project-shell">
    <ExpertProjectHeader :project="project" :compact="mdAndDown" @open-navigation="navigationOpen = true" />
    <div class="expert-project-shell__body">
      <ExpertProjectSidebar v-if="!mdAndDown" :project-id="project.id" />
      <main class="expert-project-shell__workspace"><router-view :project="project" /></main>
    </div>

    <v-navigation-drawer v-if="mdAndDown" v-model="navigationOpen" temporary location="left" width="260" class="expert-project-shell__drawer">
      <ExpertProjectSidebar :project-id="project.id" @navigated="navigationOpen = false" />
    </v-navigation-drawer>
  </div>
  <v-empty-state v-else icon="mdi-folder-alert-outline" title="Проект не найден" text="Выберите один из демонстрационных проектов Expert.">
    <template #actions><v-btn color="primary" :to="{ name: 'expert-dashboard' }">К проектам</v-btn></template>
  </v-empty-state>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import ExpertProjectHeader from '../components/ExpertProjectHeader.vue'
import ExpertProjectSidebar from '../components/ExpertProjectSidebar.vue'
import { getExpertProject } from '../mock/expertMockData'

const route = useRoute()
const { mdAndDown } = useDisplay()
const navigationOpen = ref(false)
const project = computed(() => getExpertProject(String(route.params.projectId ?? '')))

onMounted(() => window.dispatchEvent(new CustomEvent('app-sidebar:request-rail')))
onBeforeUnmount(() => window.dispatchEvent(new CustomEvent('app-sidebar:restore')))
</script>

<style scoped>
.expert-project-shell { display: flex; flex-direction: column; height: calc(100dvh - 106px); min-height: 640px; overflow: hidden; border: 1px solid rgba(var(--v-theme-outline-variant), .7); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface)); box-shadow: var(--ds-shadow-soft); }
.expert-project-shell__body { display: flex; min-height: 0; flex: 1; }
.expert-project-shell__workspace { min-width: 0; min-height: 0; flex: 1; overflow: auto; background: rgb(var(--v-theme-background)); }
.expert-project-shell__drawer :deep(.v-navigation-drawer__content) { overflow: hidden; }
.expert-project-shell__drawer :deep(.expert-project-nav) { width: 100%; }
@media (max-width: 960px) { .expert-project-shell { height: calc(100dvh - 104px); min-height: 0; border-radius: var(--md-sys-shape-corner-large); } }
@media (max-width: 600px) { .expert-project-shell { height: calc(100dvh - 88px); margin: -8px; border-radius: var(--md-sys-shape-corner-medium); } }
</style>
