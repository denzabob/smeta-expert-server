<template>
  <div class="expert-page expert-overview">
    <div class="expert-page__heading">
      <div><span class="expert-page__eyebrow">Обзор проекта</span><h1>{{ project.title }}</h1><p>Ключевые данные и готовность экспертного исследования.</p></div>
      <v-btn color="primary" variant="tonal" prepend-icon="mdi-message-text-outline" :to="projectRoute('expert-project-chat')">{{ projectMode === 'demo' ? 'Продолжить в чате' : 'Открыть чат' }}</v-btn>
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
        <v-alert v-if="!project.questions.length" type="info" variant="tonal" density="compact">Вопросы исследования пока не добавлены.</v-alert>
        <template v-if="projectMode === 'real'">
          <v-divider class="my-5" />
          <div class="expert-panel__title expert-panel__title--actions"><span><v-icon icon="mdi-package-variant" size="20" /> Объекты исследования</span><v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="openObjectForm()">Добавить</v-btn></div>
          <v-alert v-if="objectError" type="error" variant="tonal" density="compact" class="mb-3">{{ objectError }}</v-alert>
          <div v-if="project.researchObjects.length" class="expert-overview__objects">
            <article v-for="item in project.researchObjects" :key="item.id"><div><strong>{{ item.name }}</strong><small>{{ item.type || 'Тип не указан' }}</small><p v-if="item.description">{{ item.description }}</p></div><div><v-btn icon="mdi-pencil-outline" size="small" variant="text" @click="openObjectForm(item)"/><v-btn icon="mdi-delete-outline" size="small" variant="text" color="error" :loading="deletingId === item.id" @click="removeObject(item)"/></div></article>
          </div>
          <p v-else class="expert-overview__empty">Объекты исследования пока не добавлены.</p>
        </template>
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
    <v-dialog v-model="objectDialog" max-width="520"><v-card><v-card-title>{{ editingObject ? 'Редактировать объект' : 'Новый объект исследования' }}</v-card-title><v-card-text><v-text-field v-model="objectDraft.name" label="Название" variant="outlined"/><v-text-field v-model="objectDraft.type" label="Тип" variant="outlined"/><v-textarea v-model="objectDraft.description" label="Описание" variant="outlined" rows="3"/><v-alert v-if="objectFormError" type="error" variant="tonal" density="compact">{{ objectFormError }}</v-alert></v-card-text><v-card-actions><v-spacer/><v-btn :disabled="objectBusy" @click="objectDialog=false">Отмена</v-btn><v-btn color="primary" :loading="objectBusy" @click="saveObject">Сохранить</v-btn></v-card-actions></v-card></v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { getExpertProjectReadiness } from '../presentation'
import type { ExpertProject, ExpertProjectMode, ExpertResearchObject } from '../types'
import { expertApi, mapExpertApiError } from '../api'

const props = defineProps<{ project: ExpertProject; projectMode: ExpertProjectMode }>()
const infoItems = computed(() => [
  { label: 'Направление', value: props.project.direction }, { label: 'Вид работы', value: props.project.workType },
  { label: 'Заказчик', value: props.project.customer }, { label: 'Объект', value: props.project.object },
  { label: 'Адрес', value: props.project.address }, { label: 'Дата', value: props.project.researchDate }, { label: 'Статус', value: props.project.status },
])
const metrics = computed(() => props.projectMode === 'real' ? [
  { label: 'Объекты', value: props.project.counts?.researchObjects ?? props.project.researchObjects.length }, { label: 'Чаты', value: props.project.counts?.conversations ?? 0 },
  { label: 'Материалы', value: props.project.counts?.materials ?? 0 }, { label: 'Результаты', value: props.project.counts?.findings ?? 0 },
] : [
  { label: 'Материалы', value: props.project.materials.length === 5 ? 24 : 18 }, { label: 'Изображения', value: props.project.profile === 'commodity' ? 83 : 46 },
  { label: 'Факты', value: 18 }, { label: 'Измерения', value: 11 }, { label: 'Выявлено', value: props.project.findings.length }, { label: 'Нормативы', value: props.project.normatives.length },
])
const readiness = computed(() => props.projectMode === 'demo' ? getExpertProjectReadiness(props.project) : [
  { label: 'Материалы', value: (props.project.counts?.materials ?? 0) > 0 ? 'Добавлены' : 'Нет материалов', state: (props.project.counts?.materials ?? 0) > 0 ? 'complete' as const : 'draft' as const },
  { label: 'Объекты исследования', value: props.project.researchObjects.length > 0 ? 'Определены' : 'Не определены', state: props.project.researchObjects.length > 0 ? 'complete' as const : 'draft' as const },
  { label: 'Чаты', value: String(props.project.counts?.conversations ?? 0), state: (props.project.counts?.conversations ?? 0) > 0 ? 'in_progress' as const : 'draft' as const },
  { label: 'Результаты исследования', value: String(props.project.counts?.findings ?? 0), state: (props.project.counts?.findings ?? 0) > 0 ? 'in_progress' as const : 'draft' as const },
])
function readinessIcon(state: 'complete' | 'in_progress' | 'draft') { return state === 'complete' ? 'mdi-check-circle' : state === 'in_progress' ? 'mdi-progress-clock' : 'mdi-file-document-edit-outline' }
function readinessColor(state: 'complete' | 'in_progress' | 'draft') { return state === 'complete' ? 'success' : state === 'in_progress' ? 'primary' : 'secondary' }
function projectRoute(name: string): RouteLocationRaw { return { name, params: { projectId: props.project.id } } }
const objectDialog=ref(false); const objectBusy=ref(false); const deletingId=ref(''); const objectError=ref(''); const objectFormError=ref(''); const editingObject=ref<ExpertResearchObject>();
const objectDraft=reactive({name:'',type:'',description:''})
function openObjectForm(item?:ExpertResearchObject){editingObject.value=item;objectDraft.name=item?.name??'';objectDraft.type=item?.type??'';objectDraft.description=item?.description??'';objectFormError.value='';objectDialog.value=true}
async function saveObject(){if(!objectDraft.name.trim()){objectFormError.value='Укажите название объекта.';return}objectBusy.value=true;objectFormError.value='';try{const input={name:objectDraft.name.trim(),type:objectDraft.type.trim()||undefined,description:objectDraft.description.trim()||undefined};const saved=editingObject.value?await expertApi.updateResearchObject(editingObject.value.id,input):await expertApi.createResearchObject(props.project.id,input);const index=props.project.researchObjects.findIndex((item)=>item.id===saved.id);if(index>=0)props.project.researchObjects.splice(index,1,saved);else props.project.researchObjects.push(saved);if(props.project.counts)props.project.counts.researchObjects=props.project.researchObjects.length;objectDialog.value=false}catch(error){objectFormError.value=mapExpertApiError(error).message}finally{objectBusy.value=false}}
async function removeObject(item:ExpertResearchObject){deletingId.value=item.id;objectError.value='';try{await expertApi.deleteResearchObject(item.id);props.project.researchObjects=props.project.researchObjects.filter((current)=>current.id!==item.id);if(props.project.counts)props.project.counts.researchObjects=props.project.researchObjects.length}catch(error){objectError.value=mapExpertApiError(error).message}finally{deletingId.value=''}}
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
.expert-panel__title--actions { justify-content: space-between; }.expert-panel__title--actions > span { display:flex;align-items:center;gap:8px; }.expert-overview__objects { display:grid;gap:8px; }.expert-overview__objects article { display:flex;justify-content:space-between;gap:12px;padding:12px;border-radius:var(--md-sys-shape-corner-medium);background:rgb(var(--v-theme-surface-container-low)); }.expert-overview__objects strong,.expert-overview__objects small { display:block; }.expert-overview__objects small,.expert-overview__objects p,.expert-overview__empty { color:rgba(var(--v-theme-on-surface-variant),.72);font-size:.75rem; }.expert-overview__objects p { margin:5px 0 0; }
.expert-overview__metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.expert-overview__metrics div { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-radius: var(--md-sys-shape-corner-medium); background: rgb(var(--v-theme-surface-container-low)); font-size: .78rem; }
.expert-overview__metrics strong { font-size: 1rem; }
.expert-overview__checklist { display: grid; gap: 11px; }
.expert-overview__checklist > div { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 8px; font-size: .78rem; }
@media (max-width: 1100px) { .expert-overview__layout { grid-template-columns: 1fr; } }
@media (max-width: 700px) { .expert-page { padding: 18px 14px; } .expert-page__heading { align-items: stretch; flex-direction: column; } .expert-page__heading .v-btn { width: 100%; } .expert-overview__info dl { grid-template-columns: 1fr; gap: 3px; } .expert-overview__info dd { margin-bottom: 8px; } }
</style>
