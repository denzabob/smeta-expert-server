<template>
  <div v-if="project" class="expert-project-shell">
    <ExpertProjectHeader :project="project" :compact="mdAndDown" :project-mode="projectMode" @open-navigation="navigationOpen = true" />
    <div class="expert-project-shell__body">
      <ExpertProjectSidebar v-if="!mdAndDown" :project-id="project.id" :project-mode="projectMode" />
      <main class="expert-project-shell__workspace">
        <v-empty-state v-if="projectMode === 'real' && deferredSection" :icon="deferredSection.icon" :title="deferredSection.title" text="Этот раздел для реального проекта будет подключён на следующем этапе." />
        <router-view v-else :project="project" :project-mode="projectMode" />
      </main>
    </div>

    <v-navigation-drawer v-if="mdAndDown" v-model="navigationOpen" temporary location="left" width="260" class="expert-project-shell__drawer">
      <ExpertProjectSidebar :project-id="project.id" :project-mode="projectMode" @navigated="navigationOpen = false" />
    </v-navigation-drawer>
  </div>
  <div v-else-if="loading" class="expert-project-shell__state"><v-progress-circular indeterminate color="primary"/><span>Загружаем проект…</span></div>
  <v-empty-state v-else icon="mdi-folder-alert-outline" :title="notFound ? 'Проект не найден' : 'Не удалось открыть проект'" :text="errorMessage">
    <template #actions><v-btn color="primary" :to="{ name: 'expert-dashboard' }">К проектам</v-btn></template>
  </v-empty-state>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import ExpertProjectHeader from '../components/ExpertProjectHeader.vue'
import ExpertProjectSidebar from '../components/ExpertProjectSidebar.vue'
import { getExpertProject } from '../mock/expertMockData'
import { expertApi, isDemoProjectId, mapExpertApiError } from '../api'
import type { ExpertProject, ExpertProjectMode } from '../types'

const route = useRoute()
const { mdAndDown } = useDisplay()
const navigationOpen = ref(false)
const project = ref<ExpertProject>()
const projectMode = ref<ExpertProjectMode>('real')
const loading = ref(false)
const notFound = ref(false)
const errorMessage = ref('Проект недоступен. Вернитесь к списку проектов и повторите попытку.')
let loadSequence = 0
const deferredSection = computed(() => ({
  'expert-project-normatives': { title:'Нормативы — следующий этап', icon:'mdi-book-clock-outline' },
  'expert-project-report': { title:'Заключение — следующий этап', icon:'mdi-file-document-clock-outline' },
  'expert-project-versions': { title:'Версии — следующий этап', icon:'mdi-history' },
} as const)[String(route.name)] ?? null)
async function loadProject(id: string) {
  const sequence = ++loadSequence
  notFound.value=false
  if (isDemoProjectId(id)) { projectMode.value='demo'; project.value=getExpertProject(id); loading.value=false; return }
  projectMode.value='real'; project.value=undefined; loading.value=true
  try { const loaded=await expertApi.getProject(id); if(sequence===loadSequence) project.value=loaded }
  catch(error) { if(sequence===loadSequence){const mapped=mapExpertApiError(error); notFound.value=mapped.status===404; errorMessage.value=mapped.message} }
  finally { if(sequence===loadSequence) loading.value=false }
}
watch(() => String(route.params.projectId ?? ''), loadProject, { immediate:true })

onMounted(() => window.dispatchEvent(new CustomEvent('app-sidebar:request-rail')))
onBeforeUnmount(() => window.dispatchEvent(new CustomEvent('app-sidebar:restore')))
</script>

<style scoped>
.expert-project-shell { display: flex; flex-direction: column; height: calc(100dvh - 106px); min-height: 640px; overflow: hidden; border: 1px solid rgba(var(--v-theme-outline-variant), .7); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface)); box-shadow: var(--ds-shadow-soft); }
.expert-project-shell__body { display: flex; min-height: 0; flex: 1; }
.expert-project-shell__workspace { min-width: 0; min-height: 0; flex: 1; overflow: auto; background: rgb(var(--v-theme-background)); }
.expert-project-shell__drawer :deep(.v-navigation-drawer__content) { overflow: hidden; }
.expert-project-shell__drawer :deep(.expert-project-nav) { width: 100%; }
.expert-project-shell__state { display: grid; place-items: center; align-content: center; gap: 14px; min-height: 50vh; color: rgba(var(--v-theme-on-surface-variant), .8); }
@media (max-width: 960px) { .expert-project-shell { height: calc(100dvh - 104px); min-height: 0; border-radius: var(--md-sys-shape-corner-large); } }
@media (max-width: 600px) { .expert-project-shell { height: calc(100dvh - 88px); margin: -8px; border-radius: var(--md-sys-shape-corner-medium); } }
</style>
