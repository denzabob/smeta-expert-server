import type { RouteRecordRaw } from 'vue-router'

const adminPriceIndicesMeta = {
  requiresAuth: true,
  requiresAdmin: true,
  requiresPriceIndices: true,
}

export const adminPriceIndicesRoutes: RouteRecordRaw[] = [
  {
    path: 'indices/sources',
    name: 'admin-price-indices-sources',
    component: () => import('./pages/AdminPriceIndicesSourcesPage.vue'),
    meta: { ...adminPriceIndicesMeta, title: 'Источники данных' },
  },
  {
    path: 'indices/imports',
    name: 'admin-price-indices-imports',
    component: () => import('./pages/AdminPriceIndicesImportsPage.vue'),
    meta: { ...adminPriceIndicesMeta, title: 'Импорты XLSX' },
  },
  {
    path: 'indices/data',
    name: 'admin-price-indices-data',
    component: () => import('./pages/AdminPriceIndicesDataPage.vue'),
    meta: { ...adminPriceIndicesMeta, title: 'Данные' },
  },
  {
    path: 'indices/mappings',
    name: 'admin-price-indices-mappings',
    component: () => import('./pages/AdminPriceIndicesMappingsPage.vue'),
    meta: { ...adminPriceIndicesMeta, title: 'Шаблоны маппинга' },
  },
  {
    path: 'indices/logs',
    name: 'admin-price-indices-logs',
    component: () => import('./pages/AdminPriceIndicesLogsPage.vue'),
    meta: { ...adminPriceIndicesMeta, title: 'Журнал импорта' },
  },
]
