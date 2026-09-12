import type { RouteRecordRaw } from 'vue-router'

export const expertRoutes: RouteRecordRaw[] = [
  {
    path: 'expert',
    name: 'expert-dashboard',
    component: () => import('../pages/ExpertDashboard.vue'),
    meta: { title: 'Эксперт' },
  },
  {
    path: 'expert/projects',
    name: 'expert-projects',
    component: () => import('../pages/ExpertDashboard.vue'),
    meta: { title: 'Проекты — Эксперт' },
  },
  {
    path: 'expert/projects/:projectId',
    component: () => import('../pages/ExpertProject.vue'),
    meta: { title: 'Проект — Эксперт' },
    children: [
      { path: '', name: 'expert-project-overview', component: () => import('../pages/ExpertOverview.vue'), meta: { title: 'Обзор проекта — Эксперт' } },
      { path: 'chat', name: 'expert-project-chat', component: () => import('../pages/ExpertChat.vue'), meta: { title: 'Чат — Эксперт' } },
      { path: 'materials', name: 'expert-project-materials', component: () => import('../pages/ExpertMaterials.vue'), meta: { title: 'Материалы — Эксперт' } },
      { path: 'research', name: 'expert-project-research', component: () => import('../pages/ExpertResearch.vue'), meta: { title: 'Исследование — Эксперт' } },
      { path: 'normatives', name: 'expert-project-normatives', component: () => import('../pages/ExpertNormatives.vue'), meta: { title: 'Нормативы — Эксперт' } },
      { path: 'report', name: 'expert-project-report', component: () => import('../pages/ExpertReport.vue'), meta: { title: 'Заключение — Эксперт' } },
      { path: 'versions', name: 'expert-project-versions', component: () => import('../pages/ExpertVersions.vue'), meta: { title: 'Версии — Эксперт' } },
    ],
  },
]
