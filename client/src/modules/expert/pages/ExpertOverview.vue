<template>
  <div class="expert-page expert-overview">
    <div class="expert-page__heading">
      <div><span class="expert-page__eyebrow">Обзор проекта</span><h1>{{ project.title }}</h1><p>Ключевые данные и готовность экспертного исследования.</p></div>
      <v-btn color="primary" variant="tonal" prepend-icon="mdi-message-text-outline" :to="projectRoute('expert-project-chat')">Продолжить в чате</v-btn>
    </div>

    <div class="expert-overview__layout">
      <section class="expert-panel expert-overview__info">
        <div class="expert-panel__title"><v-icon icon="mdi-information-outline" size="20" /> Информация</div>
        <dl>
          <template v-for="item in infoItems" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></template>
        </dl>
        <v-divider class="my-5" />
        <h2>Вопросы исследования</h2>
        <ol><li v-for="question in project.questions" :key="question">{{ question }}</li></ol>
      </section>

      <div class="expert-overview__side">
        <section class="expert-panel">
          <div class="expert-panel__title"><v-icon icon="mdi-chart-box-outline" size="20" /> Состояние проекта</div>
          <div class="expert-overview__metrics">
            <div v-for="metric in metrics" :key="metric.label"><span>{{ metric.label }}</span><strong>{{ metric.value }}</strong></div>
          </div>
        </section>
        <section class="expert-panel">
          <div class="expert-panel__title"><v-icon icon="mdi-progress-check" size="20" /> Готовность</div>
          <div class="expert-overview__checklist">
            <div v-for="item in readiness" :key="item.label"><v-icon :icon="readinessIcon(item.state)" :color="readinessColor(item.state)" size="18" /><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { getExpertProjectReadiness } from '../presentation'
import type { ExpertProject } from '../types'

const props = defineProps<{ project: ExpertProject }>()
const infoItems = computed(() => [
  { label: 'Направление', value: props.project.direction }, { label: 'Вид работы', value: props.project.workType },
  { label: 'Заказчик', value: props.project.customer }, { label: 'Объект', value: props.project.object },
  { label: 'Адрес', value: props.project.address }, { label: 'Дата', value: props.project.researchDate }, { label: 'Статус', value: props.project.status },
])
const metrics = computed(() => [
  { label: 'Материалы', value: props.project.materials.length === 5 ? 24 : 18 }, { label: 'Изображения', value: props.project.profile === 'commodity' ? 83 : 46 },
  { label: 'Факты', value: 18 }, { label: 'Измерения', value: 11 }, { label: 'Выявлено', value: props.project.findings.length }, { label: 'Нормативы', value: props.project.normatives.length },
])
const readiness = computed(() => getExpertProjectReadiness(props.project))
function readinessIcon(state: 'complete' | 'in_progress' | 'draft') { return state === 'complete' ? 'mdi-check-circle' : state === 'in_progress' ? 'mdi-progress-clock' : 'mdi-file-document-edit-outline' }
function readinessColor(state: 'complete' | 'in_progress' | 'draft') { return state === 'complete' ? 'success' : state === 'in_progress' ? 'primary' : 'secondary' }
function projectRoute(name: string): RouteLocationRaw { return { name, params: { projectId: props.project.id } } }
</script>

<style scoped>
.expert-page { padding: 28px; }
.expert-page__heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
.expert-page__eyebrow { color: rgb(var(--v-theme-primary)); font-size: .7rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.expert-page h1 { margin: 5px 0 4px; font-size: 1.65rem; letter-spacing: -.02em; }
.expert-page__heading p { margin: 0; color: rgba(var(--v-theme-on-surface-variant), .8); font-size: .84rem; }
.expert-overview__layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(300px, .8fr); gap: 18px; }
.expert-overview__side { display: grid; align-content: start; gap: 18px; }
.expert-panel { padding: 20px; border: 1px solid rgba(var(--v-theme-outline-variant), .65); border-radius: var(--md-sys-shape-corner-large); background: rgb(var(--v-theme-surface)); }
.expert-panel__title { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; font-size: .92rem; font-weight: 800; }
.expert-overview__info dl { display: grid; grid-template-columns: 150px 1fr; gap: 11px 18px; margin: 0; }
.expert-overview__info dt { color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .78rem; }
.expert-overview__info dd { margin: 0; font-size: .84rem; font-weight: 600; }
.expert-overview__info h2 { font-size: .92rem; }
.expert-overview__info ol { display: grid; gap: 8px; margin: 12px 0 0; padding-left: 22px; color: rgba(var(--v-theme-on-surface), .84); font-size: .84rem; line-height: 1.45; }
.expert-overview__metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.expert-overview__metrics div { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-radius: var(--md-sys-shape-corner-medium); background: rgb(var(--v-theme-surface-container-low)); font-size: .78rem; }
.expert-overview__metrics strong { font-size: 1rem; }
.expert-overview__checklist { display: grid; gap: 11px; }
.expert-overview__checklist > div { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 8px; font-size: .78rem; }
@media (max-width: 1100px) { .expert-overview__layout { grid-template-columns: 1fr; } }
@media (max-width: 700px) { .expert-page { padding: 18px 14px; } .expert-page__heading { align-items: stretch; flex-direction: column; } .expert-page__heading .v-btn { width: 100%; } .expert-overview__info dl { grid-template-columns: 1fr; gap: 3px; } .expert-overview__info dd { margin-bottom: 8px; } }
</style>
