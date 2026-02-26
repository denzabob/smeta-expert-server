/**
 * Parser Module Routes
 */

import { type RouteRecordRaw } from 'vue-router'

export const parserRoutes: RouteRecordRaw[] = [
  {
    path: '/parser',
    name: 'parser',
    component: () => import('@/views/ParserDashboard.vue'),
    meta: {
      title: 'Parser Dashboard',
      requiresAuth: true
    }
  },
  {
    path: '/parser/sessions/:id',
    name: 'SessionMonitor',
    component: () => import('@/views/SessionMonitor.vue'),
    meta: {
      title: 'Session Monitor',
      requiresAuth: true
    }
  },
  {
    path: '/parser/history',
    name: 'ParserHistory',
    component: () => import('@/views/ParserHistory.vue'),
    meta: {
      title: 'Parser History',
      requiresAuth: true
    }
  },
  {
    path: '/parser/settings',
    name: 'ParserSettings',
    component: () => import('@/views/ParserSettings.vue'),
    meta: {
      title: 'Parser Settings',
      requiresAuth: true
    }
  },
  {
    path: '/parser/suppliers/:supplier/config',
    name: 'ParserSupplierConfig',
    component: () => import('@/views/ParserSupplierConfig.vue'),
    meta: {
      title: 'Supplier Config',
      requiresAuth: true
    }
  }
]
