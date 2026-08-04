import type { RouteRecordRaw } from 'vue-router'

export const priceIndicesRoutes: RouteRecordRaw[] = [
  {
    path: 'app/indices',
    name: 'price-indices-overview',
    component: () => import('../pages/PriceIndicesOverviewPage.vue'),
    meta: { requiresAuth: true, requiresPriceIndices: true, title: 'Индексы' },
  },
  {
    path: 'app/indices/new',
    name: 'price-indices-new-calculation',
    component: () => import('../pages/PriceIndicesNewCalculationPage.vue'),
    meta: { requiresAuth: true, requiresPriceIndices: true, title: 'Новый расчёт' },
  },
  {
    path: 'app/indices/calculations',
    name: 'price-indices-calculations',
    component: () => import('../pages/PriceIndicesCalculationsPage.vue'),
    meta: { requiresAuth: true, requiresPriceIndices: true, title: 'Мои расчёты' },
  },
  {
    path: 'app/indices/indicators',
    name: 'price-indices-indicators',
    component: () => import('../pages/PriceIndicesIndicatorsPage.vue'),
    meta: { requiresAuth: true, requiresPriceIndices: true, title: 'Показатели' },
  },
  {
    path: 'app/indices/sources',
    name: 'price-indices-sources',
    component: () => import('../pages/PriceIndicesSourcesPage.vue'),
    meta: { requiresAuth: true, requiresPriceIndices: true, title: 'Источники' },
  },
]
