<template>
  <main class="expert-dashboard">
    <header class="expert-dashboard__hero">
      <div>
        <div class="expert-eyebrow">PRISM WORKSPACE</div>
        <h1>Эксперт</h1>
        <p>Рабочая среда для подготовки экспертных исследований, заключений, рецензий и актов обследования.</p>
      </div>
      <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" size="large" @click="createOpen = true">
        Новое исследование
      </v-btn>
    </header>

    <section class="expert-stats" aria-label="Состояние проектов">
      <v-card v-for="stat in dashboardStats" :key="stat.label" class="expert-stat" variant="flat">
        <div class="expert-stat__icon"><v-icon :icon="stat.icon" size="22" /></div>
        <div>
          <div class="expert-stat__value">{{ stat.value }}</div>
          <div class="expert-stat__label">{{ stat.label }}</div>
        </div>
      </v-card>
    </section>

    <v-card class="expert-projects" variant="flat">
      <div class="expert-projects__header">
        <div>
          <h2>Последние проекты</h2>
          <p>Продолжите работу с исследованием или заключением</p>
        </div>
        <v-btn variant="text" color="primary" :to="{ name: 'expert-projects' }" append-icon="mdi-arrow-right">Все проекты</v-btn>
      </div>

      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <v-alert v-else-if="errorMessage" type="error" variant="tonal" class="ma-4" closable @click:close="errorMessage = ''">{{ errorMessage }} <v-btn variant="text" size="small" @click="loadProjects">Повторить</v-btn></v-alert>
      <v-alert v-else-if="!realProjects.length" type="info" variant="tonal" class="ma-4">Реальных проектов пока нет. Создайте первое исследование.</v-alert>
      <div class="expert-projects__table" role="table" aria-label="Последние проекты">
        <div class="expert-projects__row expert-projects__row--head" role="row">
          <span>Название</span><span>Направление</span><span>Вид работы</span><span>Статус</span><span>Обновлён</span>
        </div>
        <router-link
          v-for="project in displayProjects"
          :key="project.id"
          :to="{ name: 'expert-project-overview', params: { projectId: project.id } }"
          class="expert-projects__row"
          role="row"
        >
          <span class="expert-projects__title">
            <span class="expert-projects__avatar"><v-icon :icon="project.profile === 'construction' ? 'mdi-office-building-outline' : 'mdi-package-variant-closed'" size="20" /></span>
            <span><strong>{{ project.title }}</strong><small>{{ project.object }}</small></span>
          </span>
          <span data-label="Направление">{{ project.direction }}</span>
          <span data-label="Вид работы">{{ project.workType }}</span>
          <span data-label="Статус"><v-chip size="small" variant="tonal" color="primary">{{ project.status }}</v-chip></span>
          <span data-label="Обновлён">{{ project.updatedAt }}</span>
        </router-link>
      </div>
    </v-card>

    <ExpertCreateProjectModal v-model="createOpen" />
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import ExpertCreateProjectModal from '../components/ExpertCreateProjectModal.vue'
import { expertApi, mapExpertApiError } from '../api'
import type { ExpertProject } from '../types'

const route = useRoute()
const createOpen = ref(false)
const loading = ref(true)
const errorMessage = ref('')
const realProjects = ref<ExpertProject[]>([])
const displayProjects = computed(() => route.name === 'expert-projects' ? realProjects.value : realProjects.value.slice(0, 3))
const dashboardStats = computed(() => [
  { label: 'Активные проекты', value: realProjects.value.filter((item)=>item.status === 'В работе').length, icon: 'mdi-briefcase-outline' },
  { label: 'Черновики', value: realProjects.value.filter((item)=>item.status === 'Черновик').length, icon: 'mdi-file-edit-outline' },
  { label: 'Материалы', value: realProjects.value.reduce((sum,item)=>sum+(item.counts?.materials ?? 0),0), icon: 'mdi-folder-multiple-outline' },
  { label: 'Результаты', value: realProjects.value.reduce((sum,item)=>sum+(item.counts?.findings ?? 0),0), icon: 'mdi-check-circle-outline' },
])
async function loadProjects() {
  loading.value=true; errorMessage.value=''
  try { realProjects.value=await expertApi.listProjects() }
  catch(error) { errorMessage.value=mapExpertApiError(error).message }
  finally { loading.value=false }
}
onMounted(loadProjects)
</script>

<style scoped>
.expert-dashboard { display: grid; gap: 24px; max-width: 1440px; margin: 0 auto; }
.expert-dashboard__hero { display: flex; align-items: flex-end; justify-content: space-between; gap: 32px; padding: 28px 4px 8px; }
.expert-eyebrow { color: rgb(var(--v-theme-primary)); font-size: .72rem; font-weight: 800; letter-spacing: .12em; }
.expert-dashboard h1 { margin: 6px 0 8px; font-size: clamp(2rem, 4vw, 3.1rem); line-height: 1.05; letter-spacing: -.035em; color: rgb(var(--v-theme-on-background)); }
.expert-dashboard__hero p { max-width: 720px; margin: 0; color: rgba(var(--v-theme-on-surface-variant), .9); font-size: 1rem; }
.expert-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.expert-stat { display: flex; align-items: center; gap: 14px; padding: 20px; border: 1px solid rgba(var(--v-theme-outline-variant), .7); border-radius: var(--md-sys-shape-corner-large); background: rgb(var(--v-theme-surface)); }
.expert-stat__icon { display: grid; place-items: center; width: 42px; height: 42px; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .1); }
.expert-stat__value { font-size: 1.55rem; line-height: 1; font-weight: 800; color: rgb(var(--v-theme-on-surface)); }
.expert-stat__label { margin-top: 5px; color: rgba(var(--v-theme-on-surface-variant), .9); font-size: .82rem; }
.expert-projects { overflow: hidden; border: 1px solid rgba(var(--v-theme-outline-variant), .7); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface)); }
.expert-projects__header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 24px 18px; }
.expert-projects h2 { margin: 0; font-size: 1.15rem; }
.expert-projects__header p { margin: 4px 0 0; color: rgba(var(--v-theme-on-surface-variant), .82); font-size: .84rem; }
.expert-projects__table { border-top: 1px solid rgba(var(--v-theme-outline-variant), .55); }
.expert-projects__row { display: grid; grid-template-columns: minmax(260px, 1.7fr) minmax(150px, 1fr) minmax(165px, 1fr) 110px 130px; align-items: center; gap: 16px; min-height: 72px; padding: 12px 24px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .45); color: rgb(var(--v-theme-on-surface)); text-decoration: none; transition: background-color .16s ease; }
.expert-projects__row:last-child { border-bottom: 0; }
.expert-projects__row:not(.expert-projects__row--head):hover { background: rgba(var(--v-theme-primary), .045); }
.expert-projects__row--head { min-height: 40px; padding-block: 9px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; background: rgba(var(--v-theme-surface-variant), .18); }
.expert-projects__title { display: flex; align-items: center; gap: 12px; min-width: 0; }
.expert-projects__avatar { display: grid; place-items: center; flex: 0 0 38px; height: 38px; border-radius: var(--md-sys-shape-corner-medium); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .09); }
.expert-projects__title strong, .expert-projects__title small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.expert-projects__title strong { font-size: .88rem; }
.expert-projects__title small { margin-top: 3px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .75rem; }
@media (max-width: 1100px) { .expert-stats { grid-template-columns: repeat(2, 1fr); } .expert-projects__row { grid-template-columns: minmax(240px, 1.5fr) 1fr 110px 110px; } .expert-projects__row > :nth-child(3) { display: none; } }
@media (max-width: 700px) { .expert-dashboard { gap: 16px; } .expert-dashboard__hero { align-items: stretch; flex-direction: column; padding-top: 8px; gap: 18px; } .expert-dashboard__hero .v-btn { width: 100%; } .expert-stats { grid-template-columns: repeat(2, 1fr); gap: 10px; } .expert-stat { padding: 14px; } .expert-stat__icon { display: none; } .expert-projects__header { padding: 18px 16px; } .expert-projects__header .v-btn { display: none; } .expert-projects__row--head { display: none; } .expert-projects__row { grid-template-columns: 1fr auto; gap: 8px 12px; padding: 16px; } .expert-projects__row > span[data-label='Направление'], .expert-projects__row > span[data-label='Вид работы'] { display: none; } .expert-projects__row > span[data-label='Статус'] { grid-column: 2; grid-row: 1; } .expert-projects__row > span[data-label='Обновлён'] { grid-column: 1 / -1; padding-left: 50px; color: rgba(var(--v-theme-on-surface-variant), .75); font-size: .75rem; } }
</style>
